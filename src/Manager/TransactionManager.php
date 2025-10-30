<?php

declare(strict_types=1);

namespace Hibla\MySQL\Manager;

use Hibla\MySQL\Exceptions\NotInTransactionException;
use Hibla\MySQL\Exceptions\TransactionException;
use Hibla\MySQL\Exceptions\TransactionFailedException;
use Hibla\Promise\Interfaces\PromiseInterface;
use mysqli;
use Throwable;
use WeakMap;

use function Hibla\async;
use function Hibla\await;

/**
 * Manages database transactions and their callbacks.
 * 
 * This class handles transaction lifecycle including begin/commit/rollback operations,
 * retry logic, and execution of registered callbacks.
 */
final class TransactionManager
{
    /** @var WeakMap<mysqli, array{commit: list<callable(): void>, rollback: list<callable(): void>, fiber: \Fiber<mixed, mixed, mixed, mixed>|null}> Transaction callbacks using WeakMap */
    private WeakMap $transactionCallbacks;

    /**
     * Creates a new TransactionManager instance.
     */
    public function __construct()
    {
        $this->transactionCallbacks = new WeakMap();
    }

    /**
     * Registers a callback to execute when the current transaction commits.
     *
     * This method can only be called from within an active transaction.
     * The callback will be executed after the transaction successfully commits
     * but before the transaction() method returns.
     *
     * @param  callable(): void  $callback  Callback to execute on commit
     * @return void
     *
     * @throws NotInTransactionException If not currently in a transaction
     * @throws TransactionException If transaction state is corrupted
     */
    public function onCommit(callable $callback): void
    {
        $connection = $this->getCurrentTransactionConnection();

        if ($connection === null) {
            throw new NotInTransactionException(
                'onCommit() can only be called within a transaction.'
            );
        }

        if (!isset($this->transactionCallbacks[$connection])) {
            throw new TransactionException('Transaction state not found.');
        }

        $transactionData = $this->transactionCallbacks[$connection];
        $commitCallbacks = $transactionData['commit'];
        $commitCallbacks[] = $callback;

        $this->transactionCallbacks[$connection] = [
            'commit' => $commitCallbacks,
            'rollback' => $transactionData['rollback'],
            'fiber' => $transactionData['fiber'],
        ];
    }

    /**
     * Registers a callback to execute when the current transaction rolls back.
     *
     * This method can only be called from within an active transaction.
     * The callback will be executed after the transaction is rolled back
     * but before the exception is re-thrown.
     *
     * @param  callable(): void  $callback  Callback to execute on rollback
     * @return void
     *
     * @throws NotInTransactionException If not currently in a transaction
     * @throws TransactionException If transaction state is corrupted
     */
    public function onRollback(callable $callback): void
    {
        $connection = $this->getCurrentTransactionConnection();

        if ($connection === null) {
            throw new NotInTransactionException(
                'onRollback() can only be called within a transaction.'
            );
        }

        if (!isset($this->transactionCallbacks[$connection])) {
            throw new TransactionException('Transaction state not found.');
        }

        $transactionData = $this->transactionCallbacks[$connection];
        $rollbackCallbacks = $transactionData['rollback'];
        $rollbackCallbacks[] = $callback;

        $this->transactionCallbacks[$connection] = [
            'commit' => $transactionData['commit'],
            'rollback' => $rollbackCallbacks,
            'fiber' => $transactionData['fiber'],
        ];
    }

    /**
     * Executes a transaction with retry logic.
     *
     * Automatically handles transaction begin/commit/rollback. If the callback
     * throws an exception, the transaction is rolled back and retried based on
     * the specified number of attempts.
     *
     * Registered onCommit() callbacks are executed after successful commit.
     * Registered onRollback() callbacks are executed after rollback.
     *
     * @param  callable(): PromiseInterface<mysqli>  $getConnection  Callback to acquire connection
     * @param  callable(mysqli): void  $releaseConnection  Callback to release connection
     * @param  callable(mysqli): mixed  $callback  Transaction callback receiving mysqli instance
     * @param  int  $attempts  Number of times to attempt the transaction
     * @return PromiseInterface<mixed> Promise resolving to callback's return value
     *
     * @throws TransactionFailedException If transaction fails after all attempts
     * @throws \InvalidArgumentException If attempts is less than 1
     */
    public function executeTransaction(
        callable $getConnection,
        callable $releaseConnection,
        callable $callback,
        int $attempts
    ): PromiseInterface {
        return async(function () use ($getConnection, $releaseConnection, $callback, $attempts) {
            if ($attempts < 1) {
                throw new \InvalidArgumentException('Transaction attempts must be at least 1.');
            }

            /** @var Throwable|null $lastException */
            $lastException = null;

            for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
                $connection = null;

                try {
                    $connection = await($getConnection());
                    $result = await($this->runTransaction($connection, $callback));
                    return $result;
                } catch (Throwable $e) {
                    $lastException = $e;

                    if ($currentAttempt < $attempts) {
                        continue;
                    }

                    throw new TransactionFailedException(
                        sprintf(
                            'Transaction failed after %d attempt(s): %s',
                            $attempts,
                            $e->getMessage()
                        ),
                        $attempts,
                        $e
                    );
                } finally {
                    if ($connection !== null) {
                        $releaseConnection($connection);
                    }
                }
            }

            if ($lastException !== null) {
                throw new TransactionFailedException(
                    sprintf('Transaction failed after %d attempt(s)', $attempts),
                    $attempts,
                    $lastException
                );
            }

            throw new TransactionException('Transaction failed without exception.');
        });
    }

    /**
     * Runs a single transaction attempt.
     *
     * Executes BEGIN TRANSACTION, runs the callback, and either COMMIT or ROLLBACK based on success.
     * Manages transaction state and executes appropriate callbacks.
     *
     * @param  mysqli  $connection  MySQL connection
     * @param  callable(mysqli): mixed  $callback  Transaction callback
     * @return PromiseInterface<mixed> Promise resolving to callback's return value
     *
     * @throws TransactionException If BEGIN or COMMIT fails
     * @throws Throwable If callback throws (after ROLLBACK)
     */
    private function runTransaction(mysqli $connection, callable $callback): PromiseInterface
    {
        return async(function () use ($connection, $callback) {
            $currentFiber = \Fiber::getCurrent();

            /** @var array{commit: list<callable(): void>, rollback: list<callable(): void>, fiber: \Fiber<mixed, mixed, mixed, mixed>|null} $initialState */
            $initialState = [
                'commit' => [],
                'rollback' => [],
                'fiber' => $currentFiber,
            ];

            $this->transactionCallbacks[$connection] = $initialState;

            $connection->autocommit(false);
            if (!$connection->begin_transaction()) {
                throw new TransactionException(
                    'Failed to begin transaction: ' . $connection->error
                );
            }

            try {
                $result = $callback($connection);

                if (!$connection->commit()) {
                    throw new TransactionException(
                        'Failed to commit transaction: ' . $connection->error
                    );
                }
                $connection->autocommit(true);

                $this->executeCallbacks($connection, 'commit');

                return $result;
            } catch (Throwable $e) {
                $connection->rollback();
                $connection->autocommit(true);

                $this->executeCallbacks($connection, 'rollback');

                throw $e;
            } finally {
                if (isset($this->transactionCallbacks[$connection])) {
                    unset($this->transactionCallbacks[$connection]);
                }
            }
        });
    }

    /**
     * Gets the current transaction's mysqli instance if in a transaction within the current fiber.
     *
     * This method checks if the current fiber is executing within a transaction context
     * and returns the associated connection if found.
     *
     * @return mysqli|null Connection instance or null if not in transaction
     */
    private function getCurrentTransactionConnection(): ?mysqli
    {
        $currentFiber = \Fiber::getCurrent();

        foreach ($this->transactionCallbacks as $connection => $data) {
            if ($data['fiber'] === $currentFiber) {
                return $connection;
            }
        }

        return null;
    }

    /**
     * Executes registered callbacks for commit or rollback.
     *
     * Runs all callbacks registered for the specified transaction event.
     * If any callback throws an exception, execution stops and the first
     * exception is re-thrown after all callbacks have been attempted.
     *
     * @param  mysqli  $connection  MySQL connection
     * @param  string  $type  'commit' or 'rollback'
     * @return void
     *
     * @throws TransactionException If any callback throws an exception
     */
    private function executeCallbacks(mysqli $connection, string $type): void
    {
        if (!isset($this->transactionCallbacks[$connection])) {
            return;
        }

        $transactionData = $this->transactionCallbacks[$connection];

        if ($type !== 'commit' && $type !== 'rollback') {
            return;
        }

        $callbacks = $transactionData[$type];

        /** @var list<Throwable> $exceptions */
        $exceptions = [];

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                $exceptions[] = $e;
            }
        }

        if (count($exceptions) > 0) {
            throw new TransactionException(
                sprintf(
                    'Transaction %s callback failed: %s',
                    $type,
                    $exceptions[0]->getMessage()
                ),
                0,
                $exceptions[0]
            );
        }
    }
}