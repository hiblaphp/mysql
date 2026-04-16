<?php

declare(strict_types=1);

namespace Hibla\Mysql\Manager;

use Hibla\EventLoop\Loop;
use Hibla\Mysql\Exceptions\PoolException;
use Hibla\Mysql\Interfaces\ConnectionSetup as ConnectionSetupInterface;
use Hibla\Mysql\Internals\Connection as MysqlConnection;
use Hibla\Mysql\Internals\ConnectionSetup;
use Hibla\Mysql\ValueObjects\MysqlConfig;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Socket\Interfaces\ConnectorInterface;
use InvalidArgumentException;
use SplQueue;
use Throwable;

use function Hibla\async;

/**
 * @internal This is a low-level, internal class. DO NOT USE IT DIRECTLY.
 *
 * Manages a pool of asynchronous MySQL connections. This class is the core
 * component responsible for creating, reusing, and managing the lifecycle
 * of individual `Connection` objects to prevent resource exhaustion.
 *
 * All pooling logic is handled automatically by the `MysqlClient`. You should
 * never need to interact with the `PoolManager` directly.
 *
 * ## Shutdown Modes
 *
 * Two shutdown strategies are available:
 *
 * - close()      — Force shutdown. Immediately closes all connections in all
 *                  states (idle, active, draining) and rejects all waiters.
 *                  Safe to call from destructors.
 *
 * - closeAsync() — Graceful shutdown. Stops accepting new work immediately,
 *                  rejects all pending waiters, closes idle connections, then
 *                  waits for all active and draining connections to finish
 *                  naturally before tearing down. Accepts an optional timeout;
 *                  if the timeout expires, falls back to close() automatically.
 *
 * The two modes are safe to combine: calling close() while closeAsync() is
 * pending will force-resolve the shutdown promise before tearing everything
 * down, so the caller awaiting closeAsync() is never left hanging.
 *
 * ## Cancellation and Connection Reuse
 *
 * When a query is cancelled via KILL QUERY, MySQL sets a stale kill flag on
 * the server-side thread. Before this connection can be safely reused, the
 * pool must absorb this flag by issuing `DO SLEEP(0)` asynchronously. During
 * this absorption phase the connection is tracked in `$drainingConnections`
 * to guarantee it is never lost — even if `close()` is called mid-drain.
 *
 * ## Connection Reset
 *
 * If `resetConnection` is enabled, the pool will issue `COM_RESET_CONNECTION`
 * asynchronously before returning a connection to the idle pool. This clears
 * session state (variables, temporary tables, transactions) to prevent state
 * leakage between requests. This phase is also tracked via `$drainingConnections`.
 *
 * ## Query Cancellation Toggle
 *
 * The `$enableServerSideCancellation` constructor parameter controls whether
 * cancelling a query promise causes a KILL QUERY to be dispatched to the
 * server. When disabled, cancellation only transitions the promise state —
 * the server-side query runs to completion and the connection is eventually
 * returned to the pool normally.
 *
 * ## Kill Connections
 *
 * KILL QUERY requires a separate TCP connection to the same server. These
 * kill connections are intentionally created outside the pool — they are brief,
 * critical, and must never be blocked by pool capacity limits.
 *
 * ## onConnect Hook
 *
 * An optional callable may be provided to run once per physical connection
 * immediately after the MySQL handshake completes. The hook receives a
 * ConnectionSetupInterface — a minimal query surface that never leaks the
 * internal Connection object. Both sync and async (promise-returning) hooks
 * are supported. If the hook rejects or throws, the connection is dropped
 * entirely rather than returned to the pool in an unknown session state.
 *
 * This class is not subject to any backward compatibility (BC) guarantees.
 */
class PoolManager
{
    /**
     * @var SplQueue<MysqlConnection> Idle connections available for reuse.
     */
    private SplQueue $pool;

    /**
     * @var SplQueue<Promise<MysqlConnection>> Callers waiting for a connection.
     */
    private SplQueue $waiters;

    private int $maxSize;

    private int $minSize;

    private int $activeConnections = 0;

    private MysqlConfig $MysqlConfig;

    private ?ConnectorInterface $connector;

    private bool $configValidated = false;

    private int $idleTimeoutNanos;

    private int $maxLifetimeNanos;

    private int $maxWaiters;

    private float $acquireTimeout;

    private PoolException $exhaustedException;

    /**
     * Real-time count of pending waiters. Decremented via finally() hook on
     * the waiter promise, so it reflects the true number of requests still
     * waiting regardless of how the promise settles (resolve, reject, cancel).
     */
    private int $pendingWaiters = 0;

    /**
     * @var array<int, int> Last-used timestamp (nanoseconds) keyed by spl_object_id.
     */
    private array $connectionLastUsed = [];

    /**
     * @var array<int, int> Creation timestamp (nanoseconds) keyed by spl_object_id.
     */
    private array $connectionCreatedAt = [];

    /**
     * Connections currently absorbing a stale KILL flag via DO SLEEP(0) or resetting
     * via COM_RESET_CONNECTION. Tracked to prevent leaks if close() is called during drain.
     *
     * @var array<int, MysqlConnection> keyed by spl_object_id.
     */
    private array $drainingConnections = [];

    /**
     * Connections currently checked out by the client.
     * Tracked to ensure they are closed if the pool is shut down while requests are active.
     *
     * @var array<int, MysqlConnection> keyed by spl_object_id.
     */
    private array $activeConnectionsMap = [];

    /**
     * Set to true during force shutdown via close(). Causes drainAndRelease()
     * and resetAndRelease() to drop connections instead of recycling them, and
     * prevents ensureMinConnections() from spawning replacements.
     */
    private bool $isClosing = false;

    /**
     * Set to true during graceful shutdown via closeAsync(). New connection
     * requests are rejected immediately, idle connections are closed right away,
     * and the pool waits for active and draining connections to finish naturally
     * before resolving $shutdownPromise.
     *
     * Unlike $isClosing, this flag does NOT interrupt drainAndRelease() or
     * resetAndRelease() — those are allowed to complete their work so the
     * connection finishes cleanly before checkShutdownComplete() picks it up.
     */
    private bool $isGracefulShutdown = false;

    /**
     * Resolved by checkShutdownComplete() once all active and draining
     * connections have settled during a graceful shutdown. Null when no
     * graceful shutdown is in progress.
     *
     * @var Promise<void>|null
     */
    private ?Promise $shutdownPromise = null;

    /**
     * @var (callable(ConnectionSetupInterface): (PromiseInterface<mixed>|void)|null)|null
     */
    private readonly mixed $onConnect;

    /**
     * @param MysqlConfig|array<string, mixed>|string $config
     * @param int $maxSize Maximum number of connections in the pool.
     * @param int $minSize Minimum number of connections to keep open (default: 1).
     * @param int $idleTimeout Seconds before an idle connection is closed.
     * @param int $maxLifetime Seconds before a connection is rotated.
     * @param int $maxWaiters Maximum number of requests allowed in the queue
     *                        waiting for a connection. 0 means unlimited.
     * @param float $acquireTimeout Maximum seconds to wait for a connection before giving up.
     *                              0.0 means unlimited (wait forever).
     * @param bool|null $enableServerSideCancellation Whether cancelling a query promise dispatches
     *                                                KILL QUERY to the server. If null, the value
     *                                                from $config is used.
     * @param ConnectorInterface|null $connector Optional custom socket connector.
     * @param (callable(ConnectionSetupInterface): (PromiseInterface<mixed>|void))|null $onConnect
     *                                                                                             Optional hook invoked once per physical connection immediately after the MySQL
     *                                                                                             handshake completes.
     */
    public function __construct(
        MysqlConfig|array|string $config,
        int $maxSize = 10,
        int $minSize = 0,
        int $idleTimeout = 300,
        int $maxLifetime = 3600,
        int $maxWaiters = 0,
        float $acquireTimeout = 0.0,
        ?bool $enableServerSideCancellation = null,
        ?ConnectorInterface $connector = null,
        ?callable $onConnect = null,
    ) {
        $params = match (true) {
            $config instanceof MysqlConfig => $config,
            \is_array($config) => MysqlConfig::fromArray($config),
            \is_string($config) => MysqlConfig::fromUri($config),
        };

        if ($enableServerSideCancellation !== null && $params->enableServerSideCancellation !== $enableServerSideCancellation) {
            $params = $params->withQueryCancellation($enableServerSideCancellation);
        }

        $this->MysqlConfig = $params;

        if ($maxSize <= 0) {
            throw new InvalidArgumentException('Pool max size must be greater than 0');
        }

        if ($minSize < 0) {
            throw new InvalidArgumentException('Pool min connections must be 0 or greater');
        }

        if ($minSize > $maxSize) {
            throw new InvalidArgumentException(
                \sprintf('Pool min connections (%d) cannot exceed max size (%d)', $minSize, $maxSize)
            );
        }

        if ($idleTimeout <= 0) {
            throw new InvalidArgumentException('Idle timeout must be greater than 0');
        }

        if ($maxLifetime <= 0) {
            throw new InvalidArgumentException('Max lifetime must be greater than 0');
        }

        if ($maxWaiters < 0) {
            throw new InvalidArgumentException('Max waiters must be 0 or greater');
        }

        if ($acquireTimeout < 0.0) {
            throw new InvalidArgumentException('Acquire timeout must be 0.0 or greater');
        }

        // Optimization: Pre-instantiate the exception to avoid stack trace allocation
        // during high-load rejection scenarios.
        $this->exhaustedException = new PoolException(
            "Connection pool exhausted. Max waiters limit ({$maxWaiters}) reached."
        );

        $this->configValidated = true;
        $this->maxWaiters = $maxWaiters;
        $this->acquireTimeout = $acquireTimeout;
        $this->maxSize = $maxSize;
        $this->minSize = $minSize;
        $this->connector = $connector;
        $this->idleTimeoutNanos = $idleTimeout * 1_000_000_000;
        $this->maxLifetimeNanos = $maxLifetime * 1_000_000_000;
        $this->pool = new SplQueue();
        $this->waiters = new SplQueue();
        $this->onConnect = $onConnect;

        // Warm up the pool to the minimum required connections
        $this->ensureMinConnections();
    }

    /**
     * Retrieves statistics about the current state of the connection pool.
     *
     * @var array<string, bool|float|int> An associative array with pool metrics.
     */
    public array $stats {
        get {
            return [
                'active_connections' => $this->activeConnections,
                'pooled_connections' => $this->pool->count(),
                'min_size' => $this->minSize,
                'waiting_requests' => $this->pendingWaiters,
                'draining_connections' => \count($this->drainingConnections),
                'max_size' => $this->maxSize,
                'max_waiters' => $this->maxWaiters,
                'acquire_timeout' => $this->acquireTimeout,
                'config_validated' => $this->configValidated,
                'tracked_connections' => \count($this->connectionCreatedAt),
                'query_cancellation_enabled' => $this->MysqlConfig->enableServerSideCancellation,
                'compression_enabled' => $this->MysqlConfig->compress,
                'reset_connection_enabled' => $this->MysqlConfig->resetConnection,
                'multi_statements_enabled' => $this->MysqlConfig->multiStatements,
                'on_connect_hook' => $this->onConnect !== null,
                'is_graceful_shutdown' => $this->isGracefulShutdown,
            ];
        }
    }

    /**
     * Asynchronously acquires a connection from the pool.
     *
     * Rejects immediately if the pool is shutting down (either gracefully or
     * by force), so callers always get a definitive answer without waiting.
     *
     * Uses "Check-on-Borrow" strategy:
     * 1. Idle timeout exceeded → discard.
     * 2. Max lifetime exceeded → discard.
     * 3. Not ready / closed → discard.
     *
     * If no idle connection is available and the pool is not at capacity,
     * a new connection is created. Otherwise the caller is queued as a waiter.
     *
     *
     * Waiter promises support cancellation and timeouts:
     * - If cancelled before connection acquisition, it is skipped.
     * - If acquireTimeout is set and exceeded, the promise rejects with TimeoutException.
     *
     * @return PromiseInterface<MysqlConnection>
     */
    public function get(): PromiseInterface
    {
        // Reject immediately during any form of shutdown so no new work enters
        // the system. Both force-close and graceful shutdown block new borrows.
        if ($this->isClosing || $this->isGracefulShutdown) {
            return Promise::rejected(new PoolException('Pool is shutting down'));
        }

        while (! $this->pool->isEmpty()) {
            /** @var MysqlConnection $connection */
            $connection = $this->pool->dequeue();

            $connId = spl_object_id($connection);
            $now = (int) hrtime(true);
            $lastUsed = $this->connectionLastUsed[$connId] ?? 0;
            $createdAt = $this->connectionCreatedAt[$connId] ?? 0;

            if (($now - $lastUsed) > $this->idleTimeoutNanos) {
                $this->removeConnection($connection);

                continue;
            }

            if (($now - $createdAt) > $this->maxLifetimeNanos) {
                $this->removeConnection($connection);

                continue;
            }

            if (! $connection->isReady() || $connection->isClosed()) {
                $this->removeConnection($connection);

                continue;
            }

            unset($this->connectionLastUsed[$connId]);

            // Mark as active so it is tracked if closed mid-usage.
            $this->activeConnectionsMap[$connId] = $connection;

            $connection->resume();

            return Promise::resolved($connection);
        }

        if ($this->activeConnections < $this->maxSize) {
            return $this->createNewConnection();
        }

        if ($this->maxWaiters > 0 && $this->pendingWaiters >= $this->maxWaiters) {
            return Promise::rejected($this->exhaustedException);
        }

        // At capacity — enqueue a waiter.
        /** @var Promise<MysqlConnection> $waiterPromise */
        $waiterPromise = new Promise();

        if ($this->acquireTimeout > 0.0) {
            $timerId = Loop::addTimer($this->acquireTimeout, function () use ($waiterPromise): void {
                if ($waiterPromise->isPending()) {
                    $waiterPromise->reject(new TimeoutException(
                        $this->acquireTimeout
                    ));
                }
            });

            // Decrement the real-time counter and cancel the timer regardless of
            // outcome (success, failure, or user cancellation).
            $waiterPromise->finally(function () use ($timerId): void {
                $this->pendingWaiters--;
                Loop::cancelTimer($timerId);
            })->catch(static function (): void {
                // Ignore timeout rejections.
            });
        } else {
            $waiterPromise->finally(function (): void {
                $this->pendingWaiters--;
            })->catch(static function (): void {
                // Ignore cancellation or timeout rejections.
            });
        }

        $this->waiters->enqueue($waiterPromise);
        $this->pendingWaiters++;

        return $waiterPromise;
    }

    /**
     * Releases a connection back to the pool.
     *
     * Determines whether the connection needs to absorb a stale kill flag,
     * undergo a COM_RESET_CONNECTION flush, or if it can be parked cleanly.
     *
     * @param MysqlConnection $connection
     */
    public function release(MysqlConnection $connection): void
    {
        if ($connection->isClosed()) {
            $this->removeConnection($connection);
            $this->satisfyNextWaiter();

            return;
        }

        // 1. Absorb stale kill flag before the connection can be reused or reset.
        if ($connection->wasQueryCancelled()) {
            $this->drainAndRelease($connection);

            return;
        }

        // If the connection is not in a READY state (e.g., still QUERYING) and
        // was not explicitly cancelled, it means it was released in a dirty or
        // corrupted state. It cannot safely park in the idle pool.
        if (! $connection->isReady()) {
            $this->removeConnection($connection);
            $this->satisfyNextWaiter();

            return;
        }

        // 2. Perform connection state reset if enabled.
        if ($this->MysqlConfig->resetConnection) {
            $this->resetAndRelease($connection);

            return;
        }

        $this->releaseClean($connection);
    }

    /**
     * Initiates a graceful shutdown of the pool.
     *
     * Graceful shutdown proceeds in this order:
     *   1. Gates the pool — get() rejects immediately so no new work enters.
     *   2. Closes all idle connections in the pool immediately.
     *   3. Allows existing pending waiters to be served as connections finish.
     *   4. Waits for active connections and draining connections to finish.
     *   5. Resolves the returned promise once everything is empty.
     *
     * @param float $timeout Maximum seconds to wait for graceful drain before
     *                       falling back to force close(). 0.0 means no timeout.
     *
     * @return PromiseInterface<void>
     */
    public function closeAsync(float $timeout = 0.0): PromiseInterface
    {
        if ($this->isClosing) {
            $resolved = Promise::resolved();

            return $resolved;
        }

        if ($this->isGracefulShutdown) {
            if ($this->shutdownPromise !== null) {
                return $this->shutdownPromise;
            }

            $resolved = Promise::resolved();

            return $resolved;
        }

        $this->isGracefulShutdown = true;

        while (! $this->pool->isEmpty()) {
            $connection = $this->pool->dequeue();

            if (! $connection->isClosed()) {
                $connection->close();
            }

            $connId = spl_object_id($connection);
            unset(
                $this->connectionLastUsed[$connId],
                $this->connectionCreatedAt[$connId]
            );

            $this->activeConnections--;
        }

        /** @var Promise<void> $shutdownPromise */
        $shutdownPromise = new Promise();
        $this->shutdownPromise = $shutdownPromise;

        $this->checkShutdownComplete();

        if ($timeout > 0.0 && $this->shutdownPromise !== null) {
            $pendingShutdown = $this->shutdownPromise;

            $timerId = Loop::addTimer($timeout, function (): void {
                if ($this->shutdownPromise !== null && $this->shutdownPromise->isPending()) {
                    $this->close();
                }
            });

            $pendingShutdown->finally(function () use ($timerId): void {
                Loop::cancelTimer($timerId);
            })->catch(static function (): void {});
        }

        if ($this->shutdownPromise !== null) {
            return $this->shutdownPromise;
        }

        $resolved = Promise::resolved();

        return $resolved;
    }

    /**
     * Force-closes all connections in all states (idle, draining, active)
     * and rejects all pending waiters.
     *
     * If a graceful shutdown via closeAsync() is in progress, this method
     * resolves the shutdown promise before tearing everything down so the
     * caller awaiting closeAsync() is never left hanging.
     *
     * Safe to call from destructors.
     */
    public function close(): void
    {
        // If a graceful shutdown is pending, resolve it first so any caller
        // awaiting closeAsync() is not left hanging after everything is destroyed.
        if ($this->shutdownPromise !== null && $this->shutdownPromise->isPending()) {
            $this->shutdownPromise->resolve(null);
            $this->shutdownPromise = null;
        }

        $this->isGracefulShutdown = false;
        $this->isClosing = true;

        while (! $this->pool->isEmpty()) {
            $connection = $this->pool->dequeue();

            if (! $connection->isClosed()) {
                $connection->close();
            }
        }

        // Close connections that are mid-drain so they are not leaked.
        foreach ($this->drainingConnections as $connection) {
            if (! $connection->isClosed()) {
                $connection->close();
            }
        }

        $this->drainingConnections = [];

        // Close active connections to prevent hanging the event loop.
        foreach ($this->activeConnectionsMap as $connection) {
            if (! $connection->isClosed()) {
                $connection->close();
            }
        }

        $this->activeConnectionsMap = [];

        while (! $this->waiters->isEmpty()) {
            /** @var Promise<MysqlConnection> $promise */
            $promise = $this->waiters->dequeue();

            if (! $promise->isCancelled()) {
                $promise->reject(new PoolException('Pool is being closed'));
            }
        }

        $this->pool = new SplQueue();
        $this->waiters = new SplQueue();
        $this->activeConnections = 0;
        $this->pendingWaiters = 0;
        $this->connectionLastUsed = [];
        $this->connectionCreatedAt = [];
    }

    /**
     * Pings all idle connections to verify health.
     *
     * @return PromiseInterface<array<string, int>>
     */
    public function healthCheck(): PromiseInterface
    {
        /** @var Promise<array<string, int>> $promise */
        $promise = new Promise();

        $stats = [
            'total_checked' => 0,
            'healthy' => 0,
            'unhealthy' => 0,
        ];

        /** @var SplQueue<MysqlConnection> $tempQueue */
        $tempQueue = new SplQueue();

        /** @var array<int, PromiseInterface<bool>> $checkPromises */
        $checkPromises = [];

        while (! $this->pool->isEmpty()) {
            /** @var MysqlConnection $connection */
            $connection = $this->pool->dequeue();
            $stats['total_checked']++;
            $connection->resume();

            $checkPromises[] = $connection->ping()
                ->then(
                    function () use ($connection, $tempQueue, &$stats): void {
                        $stats['healthy']++;
                        $connection->pause();
                        $connId = spl_object_id($connection);
                        $this->connectionLastUsed[$connId] = (int) hrtime(true);
                        $tempQueue->enqueue($connection);
                    },
                    function () use ($connection, &$stats): void {
                        $stats['unhealthy']++;
                        $this->removeConnection($connection);
                    }
                );
        }

        Promise::all($checkPromises)
            ->then(
                function () use ($promise, $tempQueue, &$stats): void {
                    while (! $tempQueue->isEmpty()) {
                        $conn = $tempQueue->dequeue();

                        if ($this->isClosing || $this->isGracefulShutdown) {
                            $this->removeConnection($conn);
                        } else {
                            $this->pool->enqueue($conn);
                        }
                    }

                    $promise->resolve($stats);
                },
                function (Throwable $e) use ($promise, $tempQueue): void {
                    while (! $tempQueue->isEmpty()) {
                        $conn = $tempQueue->dequeue();

                        if ($this->isClosing || $this->isGracefulShutdown) {
                            $this->removeConnection($conn);
                        } else {
                            $this->pool->enqueue($conn);
                        }
                    }

                    $promise->reject($e);
                }
            )
        ;

        return $promise;
    }

    /**
     * Checks whether the graceful shutdown completion condition has been met
     * and resolves $shutdownPromise if so.
     *
     * Called at the end of every code path that removes a connection from
     * $activeConnectionsMap or $drainingConnections so no completion event
     * is ever missed.
     *
     * Completion condition: graceful shutdown is active AND both the active
     * connections map and draining connections map are fully empty.
     *
     * On completion all remaining tracking metadata is cleared and the
     * shutdown promise is resolved, unblocking any caller awaiting closeAsync().
     */
    private function checkShutdownComplete(): void
    {
        if (! $this->isGracefulShutdown) {
            return;
        }

        // If active connections are exhausted but waiters remain, they will
        // never be served (because no new connections are spawned during shutdown).
        // Reject them so they don't hang indefinitely.
        if ($this->activeConnections === 0 && ! $this->waiters->isEmpty()) {
            $shuttingDownException = new PoolException('Pool is shutting down');
            while (! $this->waiters->isEmpty()) {
                $waiter = $this->waiters->dequeue();
                if ($waiter->isPending()) {
                    $waiter->reject($shuttingDownException);
                }
            }
        }

        // Keep waiting if there are active connections or pending waiters
        if ($this->activeConnections > 0 || ! $this->waiters->isEmpty()) {
            return;
        }

        // All in-flight work has settled. Clear state and signal completion.
        $this->activeConnections = 0;
        $this->connectionLastUsed = [];
        $this->connectionCreatedAt = [];

        if ($this->shutdownPromise !== null && $this->shutdownPromise->isPending()) {
            $this->shutdownPromise->resolve(null);
        }

        $this->shutdownPromise = null;
    }

    /**
     * Runs the onConnect hook on a freshly created connection before it is
     * handed to a caller or parked in the pool.
     *
     * @return PromiseInterface<MysqlConnection>
     */
    private function runOnConnectHook(MysqlConnection $connection): PromiseInterface
    {
        if ($this->onConnect === null) {
            return Promise::resolved($connection);
        }

        $setup = new ConnectionSetup($connection);

        return async(fn() => ($this->onConnect)($setup))
            ->then(fn() => $connection);
    }

    /**
     * Absorbs a stale KILL flag by issuing `DO SLEEP(0)` on the connection.
     *
     * Not interrupted by graceful shutdown — the drain is allowed to complete
     * so the connection finishes cleanly. checkShutdownComplete() is called
     * when it exits drainingConnections naturally.
     */
    private function drainAndRelease(MysqlConnection $connection): void
    {
        $connId = spl_object_id($connection);

        unset($this->activeConnectionsMap[$connId]);

        // During force-close, drop immediately instead of draining.
        if ($this->isClosing) {
            $this->removeConnection($connection);

            return;
        }

        $this->drainingConnections[$connId] = $connection;

        $connection->query('DO SLEEP(0)')
            ->then(
                function () use ($connection, $connId): void {
                    unset($this->drainingConnections[$connId]);

                    if ($this->isClosing) {
                        $this->removeConnection($connection);

                        return;
                    }

                    $connection->clearCancelledFlag();

                    // Mark active again for releaseClean or reset logic.
                    $this->activeConnectionsMap[$connId] = $connection;

                    if ($this->MysqlConfig->resetConnection) {
                        $this->resetAndRelease($connection);
                    } else {
                        $this->releaseClean($connection);
                    }
                },
                function () use ($connection, $connId): void {
                    // ERR 1317 "Query execution was interrupted" — expected,
                    // means the kill arrived after query completion. Flag consumed.
                    unset($this->drainingConnections[$connId]);

                    if ($this->isClosing) {
                        $this->removeConnection($connection);

                        return;
                    }

                    $connection->clearCancelledFlag();

                    // Connection may no longer be ready after the error packet.
                    if ($connection->isClosed() || ! $connection->isReady()) {
                        $this->removeConnection($connection);
                        $this->satisfyNextWaiter();

                        return;
                    }

                    $this->activeConnectionsMap[$connId] = $connection;

                    if ($this->MysqlConfig->resetConnection) {
                        $this->resetAndRelease($connection);
                    } else {
                        $this->releaseClean($connection);
                    }
                }
            )
        ;
    }

    /**
     * Issues a COM_RESET_CONNECTION to clear session state before making
     * the connection available for the next caller.
     *
     * Not interrupted by graceful shutdown — see drainAndRelease() for the
     * same reasoning.
     */
    private function resetAndRelease(MysqlConnection $connection): void
    {
        $connId = spl_object_id($connection);

        unset($this->activeConnectionsMap[$connId]);

        // During force-close, drop immediately instead of resetting.
        if ($this->isClosing) {
            $this->removeConnection($connection);

            return;
        }

        $this->drainingConnections[$connId] = $connection;

        $connection->reset()->then(
            function () use ($connection, $connId): void {
                unset($this->drainingConnections[$connId]);

                if ($this->isClosing) {
                    $this->removeConnection($connection);

                    return;
                }

                $this->activeConnectionsMap[$connId] = $connection;

                // Re-run the hook — COM_RESET_CONNECTION wipes all session state
                // back to server defaults (time_zone, sql_mode, charset, etc.),
                // putting the connection in an identical state to just after the
                // initial handshake. The hook must restore it for the same reason
                // it ran at connect time.
                $this->runOnConnectHook($connection)->then(
                    fn() => $this->releaseClean($connection),
                    function (Throwable $e) use ($connection): void {
                        // Hook failed after reset — unknown session state, drop it.
                        $this->removeConnection($connection);
                        $this->satisfyNextWaiter();
                    }
                );
            },
            function () use ($connection, $connId): void {
                unset($this->drainingConnections[$connId]);

                // If reset fails, the connection state is tainted. Drop it entirely.
                $this->removeConnection($connection);
                $this->satisfyNextWaiter();
            }
        );
    }

    /**
     * Releases a clean connection: either hands it to a waiting caller,
     * parks it in the idle pool, or triggers checkShutdownComplete() if
     * a graceful shutdown is in progress and no waiters are present.
     */
    private function releaseClean(MysqlConnection $connection): void
    {
        $connId = spl_object_id($connection);

        // ALWAYS try to serve existing waiters first, even during shutdown!
        $waiter = $this->dequeueActiveWaiter();

        if ($this->waiters->isEmpty()) {
            $this->waiters = new SplQueue();
        }

        if ($waiter !== null) {
            $connection->resume();
            $waiter->resolve($connection);

            return;
        }

        // If shutting down and no waiters remain, destroy the connection.
        if ($this->isGracefulShutdown) {
            unset($this->activeConnectionsMap[$connId]);
            $this->removeConnection($connection);

            return;
        }

        // No waiters — park in idle pool.
        $connection->pause();

        $now = (int) hrtime(true);
        $createdAt = $this->connectionCreatedAt[$connId] ?? 0;

        if (($now - $createdAt) > $this->maxLifetimeNanos) {
            $this->removeConnection($connection);

            return;
        }

        $this->connectionLastUsed[$connId] = $now;

        unset($this->activeConnectionsMap[$connId]);
        $this->pool->enqueue($connection);
    }

    /**
     * Ensures that the pool maintains the minimum number of connections.
     *
     * Skipped during any form of shutdown to prevent spawning new connections
     * while the pool is being torn down.
     */
    private function ensureMinConnections(): void
    {
        if ($this->isClosing || $this->isGracefulShutdown) {
            return;
        }

        while ($this->activeConnections < $this->minSize) {
            $this->createNewConnection()->then(
                function (MysqlConnection $connection): void {
                    // Check if a waiter arrived while the connection was being established
                    $waiter = $this->dequeueActiveWaiter();

                    if ($waiter !== null) {
                        $connection->resume();
                        $waiter->resolve($connection);
                    } else {
                        // If a shutdown started while the connection was being established, drop it immediately
                        if ($this->isClosing || $this->isGracefulShutdown) {
                            $this->removeConnection($connection);

                            return;
                        }

                        // Otherwise park it in the idle pool
                        $connection->pause();
                        $connId = spl_object_id($connection);
                        $this->connectionLastUsed[$connId] = (int) hrtime(true);
                        unset($this->activeConnectionsMap[$connId]);
                        $this->pool->enqueue($connection);
                    }
                },
                function (Throwable $e): void {
                    // Ignored — the loop or next interaction will eventually retry.
                }
            );
        }
    }

    /**
     * Creates a new connection and resolves the returned promise on success.
     * Runs the onConnect hook before handing the connection to the caller.
     *
     * @return Promise<MysqlConnection>
     */
    /**
     * Creates a new connection and resolves the returned promise on success.
     * Runs the onConnect hook before handing the connection to the caller.
     *
     * @return Promise<MysqlConnection>
     */
    private function createNewConnection(): Promise
    {
        $this->activeConnections++;

        /** @var Promise<MysqlConnection> $promise */
        $promise = new Promise();

        MysqlConnection::create($this->MysqlConfig, $this->connector)
            ->then(
                function (MysqlConnection $connection) use ($promise): void {
                    // Abort immediately if the pool was force-closed mid-handshake
                    if ($this->isClosing) {
                        $connection->close();
                        $this->activeConnections--;
                        $promise->reject(new PoolException('Pool is being closed'));
                        $this->checkShutdownComplete();
                        return;
                    }

                    $connId = spl_object_id($connection);
                    $this->connectionCreatedAt[$connId] = (int) hrtime(true);
                    $this->activeConnectionsMap[$connId] = $connection;

                    $this->runOnConnectHook($connection)->then(
                        function () use ($promise, $connection): void {
                            // Check again in case pool closed during the async hook
                            if ($this->isClosing) {
                                $this->removeConnection($connection);
                                $promise->reject(new PoolException('Pool is being closed'));
                                return;
                            }

                            // If the caller cancelled the query while connecting, release 
                            // cleanly so it can be destroyed or given to the next waiter.
                            if ($promise->isCancelled()) {
                                $this->releaseClean($connection);
                                return;
                            }

                            $promise->resolve($connection);
                        },
                        function (Throwable $e) use ($promise, $connection): void {
                            $this->removeConnection($connection);
                            $promise->reject($e);
                        }
                    );
                },
                function (Throwable $e) use ($promise): void {
                    $this->activeConnections--;
                    $promise->reject($e);
                    $this->checkShutdownComplete();
                }
            )
        ;

        return $promise;
    }

    /**
     * Creates a new connection specifically to satisfy the next queued waiter.
     * Runs the onConnect hook before resolving the waiter.
     */
    private function createConnectionForWaiter(): void
    {
        $waiter = $this->dequeueActiveWaiter();

        if ($waiter === null) {
            return;
        }

        $this->activeConnections++;

        MysqlConnection::create($this->MysqlConfig, $this->connector)
            ->then(
                function (MysqlConnection $connection) use ($waiter): void {
                    if ($this->isClosing) {
                        $connection->close();
                        $this->activeConnections--;
                        $waiter->reject(new PoolException('Pool is being closed'));
                        $this->checkShutdownComplete();
                        return;
                    }

                    $connId = spl_object_id($connection);
                    $this->connectionCreatedAt[$connId] = (int) hrtime(true);
                    $this->activeConnectionsMap[$connId] = $connection;

                    $this->runOnConnectHook($connection)->then(
                        function () use ($connection, $waiter): void {
                            if ($this->isClosing) {
                                $this->removeConnection($connection);
                                $waiter->reject(new PoolException('Pool is being closed'));
                                return;
                            }

                            if ($waiter->isCancelled()) {
                                // Waiter gave up while hook was running, park cleanly.
                                $this->releaseClean($connection);
                                return;
                            }

                            $waiter->resolve($connection);
                        },
                        function (Throwable $e) use ($connection, $waiter): void {
                            $this->removeConnection($connection);
                            $waiter->reject($e);
                        }
                    );
                },
                function (Throwable $e) use ($waiter): void {
                    $this->activeConnections--;
                    $waiter->reject($e);
                    $this->checkShutdownComplete();
                }
            )
        ;
    }

    /**
     * Dequeues the next valid (pending) waiter promise, discarding any that
     * have been cancelled or rejected by acquire timeout.
     *
     * @return Promise<MysqlConnection>|null
     */
    private function dequeueActiveWaiter(): ?Promise
    {
        while (! $this->waiters->isEmpty()) {
            /** @var Promise<MysqlConnection> $waiter */
            $waiter = $this->waiters->dequeue();

            if ($waiter->isPending()) {
                return $waiter;
            }
        }

        return null;
    }

    /**
     * Satisfies the next waiter if pool capacity allows, called after a
     * connection is removed (e.g. health check failure, idle timeout eviction).
     *
     * Not called during graceful shutdown — get() is gated and no new work
     * should enter, so there are no valid waiters to satisfy.
     */
    private function satisfyNextWaiter(): void
    {
        if ($this->isGracefulShutdown || $this->isClosing) {
            return;
        }

        if (! $this->waiters->isEmpty() && $this->activeConnections < $this->maxSize) {
            $this->createConnectionForWaiter();
        }
    }

    /**
     * Closes and removes a connection, cleaning up all tracking metadata.
     *
     * After removing the connection, calls checkShutdownComplete() so that
     * any in-progress graceful shutdown can detect the drained state.
     */
    private function removeConnection(MysqlConnection $connection): void
    {
        if (! $connection->isClosed()) {
            $connection->close();
        }

        $connId = spl_object_id($connection);
        unset(
            $this->connectionLastUsed[$connId],
            $this->connectionCreatedAt[$connId],
            $this->drainingConnections[$connId],
            $this->activeConnectionsMap[$connId]
        );

        $this->activeConnections--;

        // Only replenish during normal operation.
        if (! $this->isClosing && ! $this->isGracefulShutdown) {
            $this->ensureMinConnections();
        }

        // Always check — this call is a no-op when not in graceful shutdown.
        $this->checkShutdownComplete();
    }

    public function __destruct()
    {
        $this->close();
    }
}
