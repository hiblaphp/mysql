<?php

declare(strict_types=1);

namespace Hibla\Mysql\Internals;

use Hibla\Cache\ArrayCache;
use Hibla\Mysql\Interfaces\MysqlResult;
use Hibla\Mysql\Interfaces\MysqlRowStream;
use Hibla\Mysql\Manager\PoolManager;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Sql\Exceptions\TransactionException;
use Hibla\Sql\PreparedStatement as PreparedStatementInterface;
use Hibla\Sql\Result as ResultInterface;
use Hibla\Sql\Transaction as TransactionInterface;

/**
 * Transaction implementation with automatic pool management and state protection.
 *
 * @internal Created by MysqlClient::beginTransaction() - do not instantiate directly.
 */
class Transaction implements TransactionInterface
{
    /**
     * @var list<callable(): void>
     */
    private array $onCommitCallbacks = [];

    /**
     * @var list<callable(): void>
     */
    private array $onRollbackCallbacks = [];

    private bool $active = true;

    private bool $released = false;

    /**
     * If a query fails mid-transaction, the transaction becomes "tainted".
     * MySQL may allow further queries, but it is dangerous and can lead to
     * partial commits depending on the storage engine or implicit commits.
     * We enforce a strict failure state requiring an explicit ROLLBACK.
     */
    private bool $failed = false;

    /**
     * @internal Use MysqlClient::beginTransaction() instead.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly PoolManager $pool,
        private readonly ?ArrayCache $statementCache = null
    ) {
    }

    /**
     * {@inheritdoc}
     *
     * - If `$params` are provided, it uses a secure PREPARED STATEMENT (Binary Protocol).
     * - If no `$params` are provided, it uses a non-prepared query (Text Protocol).
     *
     * @return PromiseInterface<MysqlResult>
     */
    public function query(string $sql, array $params = []): PromiseInterface
    {
        $this->ensureActiveAndNotFailed();

        if (\count($params) === 0) {
            $promise = $this->connection->query($sql);

            return Promise::propagateCancellation($this->trackErrorState($promise));
        }

        $innerPromise = null;

        $promise = $this->getCachedStatement($sql)
            ->then(function (array $result) use ($params, &$innerPromise) {
                /** @var PreparedStatement $stmt */
                [$stmt, $isCached] = $result;

                $innerPromise = $stmt->execute($params)
                    ->finally(function () use ($stmt, $isCached) {
                        if (! $isCached) {
                            $stmt->close();
                        }
                    })
                ;

                return $innerPromise;
            })
        ;

        Promise::forwardCancellation($promise, $innerPromise);

        return Promise::propagateCancellation($this->trackErrorState($promise));
    }

    /**
     * {@inheritdoc}
     *
     * - If `$params` are provided, it uses a secure PREPARED STATEMENT (Binary Protocol).
     * - If no `$params` are provided, it uses a non-prepared query (Text Protocol).
     *
     * @param string $sql SQL query to stream
     * @param array<int|string, mixed> $params Query parameters (optional)
     * @param int $bufferSize Maximum rows to buffer before applying backpressure (default: 100)
     *
     * @return PromiseInterface<MysqlRowStream>
     */
    public function stream(string $sql, array $params = [], int $bufferSize = 100): PromiseInterface
    {
        $this->ensureActiveAndNotFailed();

        if (\count($params) === 0) {
            $promise = $this->connection->streamQuery($sql, $bufferSize);

            // Track taint state from both the outer borrow promise (pre-first-row errors
            // and outer cancellations) and the inner command promise (mid-iteration errors
            // and $stream->cancel() calls inside a foreach loop).
            $tracked = $this->trackErrorState($promise)->then(
                function (MysqlRowStream $stream): MysqlRowStream {
                    $this->bindStreamErrorState($stream);

                    return $stream;
                }
            );

            return Promise::propagateCancellation($tracked);
        }

        $innerPromise = null;

        $promise = $this->getCachedStatement($sql)
            ->then(function (array $result) use ($params, $bufferSize, &$innerPromise) {
                /** @var PreparedStatement $stmt */
                [$stmt, $isCached] = $result;

                $innerPromise = $stmt->executeStream($params, $bufferSize)
                    ->then(function (MysqlRowStream $stream) use ($stmt, $isCached): MysqlRowStream {
                        if ($stream instanceof RowStream) {
                            // Hook into mid-iteration errors/cancellations before registering
                            // the statement-close callback so both share the same underlying
                            // command promise without ordering dependencies.
                            $this->bindStreamErrorState($stream);

                            if (! $isCached) {
                                $stream->waitForCommand()->finally($stmt->close(...));
                            }
                        }

                        return $stream;
                    })
                ;

                return $innerPromise;
            })
        ;

        Promise::forwardCancellation($promise, $innerPromise);

        return Promise::propagateCancellation($this->trackErrorState($promise));
    }

    /**
     * {@inheritdoc}
     *
     * The $onStreamError closure passed to TransactionPreparedStatement lets it
     * taint this transaction when a stream returned by the statement is cancelled
     * or errors out mid-iteration — a lifecycle event that occurs entirely outside
     * the promise chain this Transaction can observe directly.
     *
     * @return PromiseInterface<PreparedStatementInterface>
     */
    public function prepare(string $sql): PromiseInterface
    {
        $this->ensureActiveAndNotFailed();

        $innerPromise = $this->connection->prepare($sql);

        $onStreamError = function (): void {
            $this->failed = true;
        };

        $promise = $innerPromise->then(
            function (PreparedStatementInterface $stmt) use ($onStreamError) {
                return new TransactionPreparedStatement($stmt, $this->connection, $onStreamError);
            }
        );

        Promise::forwardCancellation($promise, $innerPromise);

        return $this->trackErrorState($promise);
    }

    /**
     * {@inheritdoc}
     */
    public function execute(string $sql, array $params = []): PromiseInterface
    {
        return Promise::propagateCancellation(
            $this->query($sql, $params)->then(fn (ResultInterface $result) => $result->affectedRows)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function executeGetId(string $sql, array $params = []): PromiseInterface
    {
        return Promise::propagateCancellation(
            $this->query($sql, $params)->then(function (ResultInterface $result) {
                $row = $result->fetchOne();
                if ($row !== null && \count($row) > 0) {
                    $val = reset($row);

                    return \is_scalar($val) ? (int) $val : 0;
                }

                return $result->lastInsertId;
            })
        );
    }

    /**
     * {@inheritdoc}
     */
    public function fetchOne(string $sql, array $params = []): PromiseInterface
    {
        return Promise::propagateCancellation(
            $this->query($sql, $params)->then(fn (ResultInterface $result) => $result->fetchOne())
        );
    }

    /**
     * {@inheritdoc}
     */
    public function fetchValue(string $sql, string|int|null $column = null, array $params = []): PromiseInterface
    {
        return Promise::propagateCancellation(
            $this->query($sql, $params)
                ->then(function (ResultInterface $result) use ($column) {
                    $row = $result->fetchOne();

                    if ($row === null) {
                        return null;
                    }

                    if ($column === null) {
                        $value = reset($row);

                        return $value !== false ? $value : null;
                    }

                    if (\is_int($column)) {
                        $values = array_values($row);

                        return $values[$column] ?? null;
                    }

                    return $row[$column] ?? null;
                })
        );
    }

    /**
     * {@inheritdoc}
     */
    public function onCommit(callable $callback): void
    {
        $this->ensureActive();
        $this->onCommitCallbacks[] = $callback;
    }

    /**
     * {@inheritdoc}
     */
    public function onRollback(callable $callback): void
    {
        $this->ensureActive();
        $this->onRollbackCallbacks[] = $callback;
    }

    /**
     * {@inheritdoc}
     *
     * NOTE: This method is intentionally shielded from user cancellations.
     * Cancelling a COMMIT mid-flight would leave the transaction in an undefined
     * state on the server. The `shield()` wrapper guarantees the COMMIT query
     * finishes and its `finally()` block safely releases the connection, even if
     * the caller cancels the returned promise.
     *
     * NOTE: `$this->active` is set to false inside the query callbacks rather
     * than before the query is dispatched. This ensures that if the server rejects
     * COMMIT (e.g. connection dropped), the user can still invoke rollback() to
     * cleanly close out the transaction and release the connection.
     *
     * @return PromiseInterface<void>
     */
    public function commit(): PromiseInterface
    {
        $this->ensureActive();

        if ($this->failed) {
            return Promise::rejected(
                new TransactionException(
                    'Transaction aborted due to a previous query error. '
                        . 'Call rollback() to abort, or use savepoints to recover from expected failures.'
                )
            );
        }

        $promise = $this->connection->query('COMMIT')
            ->then(
                function (): void {
                    // Only mark inactive on confirmed success so that a rejected
                    // COMMIT still allows rollback() to run and release cleanly.
                    $this->active = false;
                    $this->executeCallbacks($this->onCommitCallbacks);
                    $this->onRollbackCallbacks = [];
                },
                function (\Throwable $e): never {
                    // COMMIT was rejected by the server. Mark the transaction as
                    // failed and inactive so neither commit() nor query() can be
                    // retried on a connection whose state is now undefined.
                    $this->active = false;
                    $this->failed = true;

                    throw new TransactionException(
                        'Failed to commit transaction: ' . $e->getMessage(),
                        (int) $e->getCode(),
                        $e
                    );
                }
            )
            ->finally($this->releaseConnection(...))
        ;

        return Promise::uninterruptible($promise);
    }

    /**
     * {@inheritdoc}
     *
     * NOTE: This method is shielded for the same reason as `commit()`.
     * Dispatching a KILL QUERY against a ROLLBACK leaves the server state undefined.
     *
     * NOTE: rollback() is idempotent — calling it on an already-rolled-back or
     * already-committed transaction silently returns a resolved promise rather than
     * throwing. This allows callers to safely place rollback() in finally blocks.
     *
     * NOTE: When the underlying connection has been closed (e.g. via the opt-out
     * cancellation path where enableServerSideCancellation is false), rollback()
     * cannot send ROLLBACK to the server. It still releases the (now-closed)
     * connection back to the pool so the pool's activeConnections counter is
     * decremented and queued waiters can be served.
     *
     * NOTE: When a query was recently cancelled via KILL QUERY (wasQueryCancelled=true),
     * releaseConnection() is called synchronously BEFORE awaiting the ROLLBACK promise.
     * This immediately decrements `active_connections` so stats are correct even if
     * the await is interrupted. The pool safely routes this connection to `DO SLEEP(0)`
     * to absorb the stale KILL flag while the queued ROLLBACK finishes.
     *
     * @return PromiseInterface<void>
     */
    public function rollback(): PromiseInterface
    {
        // Idempotency guard: already committed, rolled back, or otherwise closed.
        if (! $this->active) {
            return Promise::resolved();
        }

        // Opt-out cancellation path: the connection was closed because a query
        // was cancelled without side-channel kills enabled. The connection is dead,
        // but we must still release it to decrement the pool's active count.
        if ($this->connection->isClosed()) {
            $this->active = false;
            $this->failed = false;
            $this->releaseConnection();

            return Promise::resolved();
        }

        // Interrupt any running query so the wire is free to receive the ROLLBACK immediately.
        // This bridges the gap when the Fiber is killed but the underlying query promise is orphaned.
        $this->connection->cancelCurrentCommand();

        $this->active = false;
        $this->failed = false;

        $promise = $this->connection->query('ROLLBACK')
            ->then(
                function (): void {
                    $this->executeCallbacks($this->onRollbackCallbacks);
                    $this->onCommitCallbacks = [];
                },
                function (\Throwable $e): void {
                    // MariaDB/MySQL Error 1317: Query execution was interrupted.
                    // This happens if the KILL QUERY side-channel reaches the server exactly
                    // when the ROLLBACK starts, or if MariaDB is still cleaning up the
                    // thread interrupt. Since we are rolling back anyway, we treat
                    // this as a successful termination of the transaction.
                    if ($e->getCode() === 1317) {
                        $this->executeCallbacks($this->onRollbackCallbacks);
                        $this->onCommitCallbacks = [];

                        return;
                    }

                    throw new TransactionException(
                        'Failed to rollback transaction: ' . $e->getMessage(),
                        (int) $e->getCode(),
                        $e
                    );
                }
            )
        ;

        // KILL QUERY path: release synchronously so pool stats are correct immediately.
        if ($this->connection->wasQueryCancelled()) {
            $this->releaseConnection();

            return Promise::uninterruptible($promise);
        }

        // Normal path: release only after ROLLBACK completes to prevent a dirty
        // connection from being parked in the idle pool.
        return Promise::uninterruptible($promise->finally($this->releaseConnection(...)));
    }

    /**
     * {@inheritdoc}
     *
     * @return PromiseInterface<void>
     */
    public function savepoint(string $identifier): PromiseInterface
    {
        $this->ensureActiveAndNotFailed();
        $escaped = $this->escapeIdentifier($identifier);

        $promise = $this->connection->query("SAVEPOINT {$escaped}")->catch(
            function (\Throwable $e) use ($identifier): never {
                throw new TransactionException(
                    "Failed to create savepoint '{$identifier}': " . $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }
        );

        return Promise::propagateCancellation($this->trackErrorState($promise));
    }

    /**
     * {@inheritdoc}
     *
     * @return PromiseInterface<void>
     */
    public function rollbackTo(string $identifier): PromiseInterface
    {
        $this->ensureActive();
        $escaped = $this->escapeIdentifier($identifier);

        // Rolling back to a savepoint potentially clears the failed state for operations after that savepoint
        $this->failed = false;

        $promise = $this->connection->query("ROLLBACK TO SAVEPOINT {$escaped}")->catch(
            function (\Throwable $e) use ($identifier): never {
                $this->failed = true; // If rollback fails, the whole transaction is dead

                throw new TransactionException(
                    "Failed to rollback to savepoint '{$identifier}': " . $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }
        );

        return Promise::propagateCancellation($promise);
    }

    /**
     * {@inheritdoc}
     *
     * @return PromiseInterface<void>
     */
    public function releaseSavepoint(string $identifier): PromiseInterface
    {
        $this->ensureActiveAndNotFailed();
        $escaped = $this->escapeIdentifier($identifier);

        $promise = $this->connection->query("RELEASE SAVEPOINT {$escaped}")->catch(
            function (\Throwable $e) use ($identifier): never {
                throw new TransactionException(
                    "Failed to release savepoint '{$identifier}': " . $e->getMessage(),
                    (int) $e->getCode(),
                    $e
                );
            }
        );

        return Promise::propagateCancellation($this->trackErrorState($promise));
    }

    /**
     * {@inheritdoc}
     */
    public function isActive(): bool
    {
        return $this->active && ! $this->connection->isClosed();
    }

    /**
     * {@inheritdoc}
     */
    public function isClosed(): bool
    {
        return $this->connection->isClosed();
    }

    /**
     * Force-cancels any running query on the connection.
     * Call this before rollback() if the transaction fiber was killed.
     */
    public function forceCancelCurrentQuery(): void
    {
        if (! $this->connection->isClosed()) {
            $this->connection->cancelCurrentCommand();
        }
    }

    /**
     * Wraps a promise so that any rejection or cancellation marks the transaction
     * as failed, enforcing strict state management for MySQL transactions.
     *
     * Cancellation is handled separately via onCancel() because cancelled promises
     * short-circuit the chain before .catch() handlers fire. Without the onCancel
     * hook, cancelling a query promise would successfully send KILL QUERY, but
     * `$this->failed` would remain false.
     *
     * @template T
     *
     * @param PromiseInterface<T> $promise
     *
     * @return PromiseInterface<T>
     */
    private function trackErrorState(PromiseInterface $promise): PromiseInterface
    {
        $promise->onCancel(function (): void {
            $this->failed = true;
        });

        return $promise->catch(function (\Throwable $e) {
            $this->failed = true;

            throw $e;
        });
    }

    /**
     * Hooks into a RowStream to taint the transaction if the stream is cancelled
     * or errors out during mid-iteration consumption.
     *
     * Two complementary hooks are registered:
     *
     *   1. $stream->onCancel() — fires unconditionally whenever $stream->cancel()
     *      is called, even when the command promise is already settled (i.e. all rows
     *      arrived in one chunk before iteration started).
     *
     *   2. commandPromise onCancel / catch — fires when the server-side command is
     *      cancelled or errors out asynchronously. Only registered when the command promise
     *      is still pending to avoid redundant no-op registrations.
     *
     * @param RowStream $stream The already-resolved stream whose lifecycle to observe.
     */
    private function bindStreamErrorState(RowStream $stream): void
    {
        $stream->onCancel(function (): void {
            $this->failed = true;
        });

        $cmd = $stream->waitForCommand();
        if (! $cmd->isSettled()) {
            $cmd->onCancel(function (): void {
                $this->failed = true;
            });
            $cmd->catch(function (): void {
                $this->failed = true;
            });
        }
    }

    /**
     * Helper to get a prepared statement from cache or create a new one.
     * Returns an array [PreparedStatement, bool isCached].
     *
     * @return PromiseInterface<array{0: PreparedStatement, 1: bool}>
     */
    private function getCachedStatement(string $sql): PromiseInterface
    {
        if ($this->statementCache === null) {
            return $this->connection->prepare($sql)->then(fn ($stmt) => [$stmt, false]);
        }

        return $this->statementCache->get($sql)->then(function (mixed $stmt) use ($sql) {
            if ($stmt instanceof PreparedStatement) {
                return [$stmt, true];
            }

            return $this->connection->prepare($sql)->then(function (PreparedStatement $newStmt) use ($sql) {
                $this->statementCache->set($sql, $newStmt);

                return [$newStmt, true];
            });
        });
    }

    private function releaseConnection(): void
    {
        if ($this->released) {
            return;
        }

        $this->onCommitCallbacks = [];
        $this->onRollbackCallbacks = [];
        $this->released = true;
        $this->pool->release($this->connection);
    }

    /**
     * @param list<callable(): void> $callbacks
     */
    private function executeCallbacks(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            $callback();
        }
    }

    private function ensureActive(): void
    {
        if ($this->connection->isClosed()) {
            throw new TransactionException('Cannot perform operation: connection is closed');
        }

        if (! $this->active) {
            throw new TransactionException('Cannot perform operation: transaction is no longer active');
        }
    }

    private function ensureActiveAndNotFailed(): void
    {
        $this->ensureActive();

        if ($this->failed) {
            throw new TransactionException(
                'Transaction aborted due to a previous query error. '
                    . 'Call rollback() to abort, or use savepoints to recover from expected failures.'
            );
        }
    }

    private function escapeIdentifier(string $identifier): string
    {
        if ($identifier === '') {
            throw new \InvalidArgumentException('Savepoint identifier cannot be empty');
        }

        if (\strlen($identifier) > 64) {
            throw new \InvalidArgumentException('Savepoint identifier too long (max 64 characters)');
        }

        if (strpos($identifier, "\0") !== false || strpos($identifier, "\xFF") !== false) {
            throw new \InvalidArgumentException('Savepoint identifier contains invalid byte values');
        }

        if ($identifier !== trim($identifier)) {
            throw new \InvalidArgumentException('Savepoint identifier cannot start or end with spaces');
        }

        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Destructor ensures the connection is released safely.
     *
     * If the transaction was not explicitly committed or rolled back (e.g. an exception
     * was thrown and the variable went out of scope), it issues an asynchronous
     * fire-and-forget ROLLBACK to clear the session state before returning the
     * connection to the pool.
     */
    public function __destruct()
    {
        if ($this->active && ! $this->connection->isClosed() && ! $this->released) {
            $this->active = false;
            $this->connection->query('ROLLBACK')->finally($this->releaseConnection(...));
        } elseif (! $this->released) {
            $this->releaseConnection();
        }
    }
}
