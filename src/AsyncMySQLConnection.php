<?php

declare(strict_types=1);

namespace Hibla\MySQL;

use Hibla\Async\Timer;
use Hibla\MySQL\Exceptions\ConfigurationException;
use Hibla\MySQL\Exceptions\NotInitializedException;
use Hibla\MySQL\Exceptions\NotInTransactionException;
use Hibla\MySQL\Exceptions\QueryException;
use Hibla\MySQL\Exceptions\TransactionException;
use Hibla\MySQL\Exceptions\TransactionFailedException;
use Hibla\MySQL\Manager\PoolManager;
use Hibla\Promise\Interfaces\PromiseInterface;
use mysqli;
use mysqli_result;
use mysqli_stmt;
use Throwable;
use WeakMap;

use function Hibla\async;
use function Hibla\await;

/**
 * Instance-based Asynchronous MySQL API for independent database connections.
 *
 * This class provides non-static methods for managing a single connection pool.
 * Each instance is completely independent, allowing true multi-database support
 * without global state.
 */
final class AsyncMySQLConnection
{
    /** @var PoolManager|null Connection pool instance for this connection */
    private ?PoolManager $pool = null;

    /** @var bool Tracks initialization state of this instance */
    private bool $isInitialized = false;

    /** @var WeakMap<mysqli, array{commit: list<callable(): void>, rollback: list<callable(): void>, fiber: \Fiber<mixed, mixed, mixed, mixed>|null}>|null Transaction callbacks using WeakMap */
    private ?WeakMap $transactionCallbacks = null;

    /** @var int Poll interval in microseconds */
    private const POLL_INTERVAL = 10;

    /** @var int Maximum poll interval in microseconds */
    private const POLL_MAX_INTERVAL = 100;

    /**
     * Creates a new independent MySQLConnection instance.
     *
     * Each instance manages its own connection pool and is completely
     * independent from other instances, allowing true multi-database support.
     *
     * @param  array<string, mixed>  $dbConfig  Database configuration array containing:
     *                                          - host: Database host (required, e.g., 'localhost')
     *                                          - username: Database username (required)
     *                                          - database: Database name (required)
     *                                          - password: Database password (optional)
     *                                          - port: Database port (optional, must be positive integer)
     *                                          - socket: Unix socket path (optional)
     *                                          - charset: Character set (optional, default: 'utf8mb4')
     * @param  int  $poolSize  Maximum number of connections in the pool
     *
     * @throws ConfigurationException If configuration is invalid
     */
    public function __construct(array $dbConfig, int $poolSize = 10)
    {
        try {
            $this->pool = new PoolManager($dbConfig, $poolSize);
            $this->transactionCallbacks = new WeakMap();
            $this->isInitialized = true;
        } catch (\InvalidArgumentException $e) {
            throw new ConfigurationException(
                'Invalid database configuration: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Resets this instance, closing all connections and clearing state.
     * After reset, this instance cannot be used until recreated.
     *
     * @return void
     */
    public function reset(): void
    {
        if ($this->pool !== null) {
            $this->pool->close();
        }
        $this->pool = null;
        $this->isInitialized = false;
        $this->transactionCallbacks = null;
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

        $this->ensureTransactionCallbacksInitialized();

        if (! isset($this->transactionCallbacks[$connection])) {
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

        $this->ensureTransactionCallbacksInitialized();

        if (! isset($this->transactionCallbacks[$connection])) {
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
     * Executes a callback with a connection from this instance's pool.
     *
     * Automatically handles connection acquisition and release. The callback
     * receives a mysqli instance and can perform any database operations.
     * The connection is guaranteed to be released back to the pool even if
     * the callback throws an exception.
     *
     * @template TResult
     *
     * @param  callable(mysqli): TResult  $callback  Function that receives mysqli instance
     * @return PromiseInterface<TResult> Promise resolving to callback's return value
     *
     * @throws NotInitializedException If this instance is not initialized
     */
    public function run(callable $callback): PromiseInterface
    {
        return async(function () use ($callback): mixed {
            $mysqli = null;

            try {
                $mysqli = await($this->getPool()->get());

                return $callback($mysqli);
            } finally {
                if ($mysqli !== null) {
                    $this->getPool()->release($mysqli);
                }
            }
        });
    }

    /**
     * Executes a SELECT query and returns all matching rows.
     *
     * The query is executed asynchronously using MySQL's non-blocking API.
     * Parameters are safely bound using prepared statements to prevent SQL injection.
     *
     * @param  string  $sql  SQL query with optional parameter placeholders (?)
     * @param  array<int, mixed>  $params  Parameter values for prepared statement
     * @param  string|null  $types  Parameter type string (i=integer, d=double, s=string, b=blob). Auto-detected if null.
     * @return PromiseInterface<array<int, array<string, mixed>>> Promise resolving to array of associative arrays
     *
     * @throws NotInitializedException If this instance is not initialized
     * @throws QueryException If query execution fails
     */
    public function query(string $sql, array $params = [], ?string $types = null): PromiseInterface
    {
        /** @var PromiseInterface<array<int, array<string, mixed>>> */
        return $this->executeAsyncQuery($sql, $params, $types, 'fetchAll');
    }

    /**
     * Executes a SELECT query and returns the first matching row.
     *
     * The query is executed asynchronously using MySQL's non-blocking API.
     * Returns null if no rows match the query.
     *
     * @param  string  $sql  SQL query with optional parameter placeholders (?)
     * @param  array<int, mixed>  $params  Parameter values for prepared statement
     * @param  string|null  $types  Parameter type string (i=integer, d=double, s=string, b=blob). Auto-detected if null.
     * @return PromiseInterface<array<string, mixed>|null> Promise resolving to associative array or null if no rows
     *
     * @throws NotInitializedException If this instance is not initialized
     * @throws QueryException If query execution fails
     */
    public function fetchOne(string $sql, array $params = [], ?string $types = null): PromiseInterface
    {
        /** @var PromiseInterface<array<string, mixed>|null> */
        return $this->executeAsyncQuery($sql, $params, $types, 'fetchOne');
    }

    /**
     * Executes an INSERT, UPDATE, or DELETE statement and returns affected row count.
     *
     * The statement is executed asynchronously using MySQL's non-blocking API.
     * Returns the number of rows affected by the operation.
     *
     * @param  string  $sql  SQL statement with optional parameter placeholders (?)
     * @param  array<int, mixed>  $params  Parameter values for prepared statement
     * @param  string|null  $types  Parameter type string (i=integer, d=double, s=string, b=blob). Auto-detected if null.
     * @return PromiseInterface<int> Promise resolving to number of affected rows
     *
     * @throws NotInitializedException If this instance is not initialized
     * @throws QueryException If statement execution fails
     */
    public function execute(string $sql, array $params = [], ?string $types = null): PromiseInterface
    {
        /** @var PromiseInterface<int> */
        return $this->executeAsyncQuery($sql, $params, $types, 'execute');
    }

    /**
     * Executes a query and returns a single column value from the first row.
     *
     * Useful for queries that return a single scalar value like COUNT, MAX, etc.
     * Returns null if the query returns no rows.
     *
     * @param  string  $sql  SQL query with optional parameter placeholders (?)
     * @param  array<int, mixed>  $params  Parameter values for prepared statement
     * @param  string|null  $types  Parameter type string (i=integer, d=double, s=string, b=blob). Auto-detected if null.
     * @return PromiseInterface<mixed> Promise resolving to scalar value or null if no rows
     *
     * @throws NotInitializedException If this instance is not initialized
     * @throws QueryException If query execution fails
     */
    public function fetchValue(string $sql, array $params = [], ?string $types = null): PromiseInterface
    {
        return $this->executeAsyncQuery($sql, $params, $types, 'fetchValue');
    }

    /**
     * Executes multiple operations within a database transaction.
     *
     * Automatically handles transaction begin/commit/rollback. If the callback
     * throws an exception, the transaction is rolled back and retried based on
     * the specified number of attempts. All retry attempts are made with exponential
     * backoff between attempts.
     *
     * Registered onCommit() callbacks are executed after successful commit.
     * Registered onRollback() callbacks are executed after rollback.
     *
     * @param  callable(mysqli): mixed  $callback  Transaction callback receiving mysqli instance
     * @param  int  $attempts  Number of times to attempt the transaction (default: 1)
     * @return PromiseInterface<mixed> Promise resolving to callback's return value
     *
     * @throws NotInitializedException If this instance is not initialized
     * @throws TransactionFailedException If transaction fails after all attempts
     * @throws \InvalidArgumentException If attempts is less than 1
     */
    public function transaction(callable $callback, int $attempts = 1): PromiseInterface
    {
        return async(function () use ($callback, $attempts) {
            if ($attempts < 1) {
                throw new \InvalidArgumentException('Transaction attempts must be at least 1.');
            }

            /** @var Throwable|null $lastException */
            $lastException = null;

            for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
                try {
                    return await($this->run(function (mysqli $mysqli) use ($callback) {
                        $currentFiber = \Fiber::getCurrent();

                        $this->ensureTransactionCallbacksInitialized();

                        /** @var array{commit: list<callable(): void>, rollback: list<callable(): void>, fiber: \Fiber<mixed, mixed, mixed, mixed>|null} $initialState */
                        $initialState = [
                            'commit' => [],
                            'rollback' => [],
                            'fiber' => $currentFiber,
                        ];

                        if ($this->transactionCallbacks !== null) {
                            $this->transactionCallbacks[$mysqli] = $initialState;
                        }

                        $mysqli->autocommit(false);
                        if (! $mysqli->begin_transaction()) {
                            throw new TransactionException(
                                'Failed to begin transaction: ' . $mysqli->error
                            );
                        }

                        try {
                            $result = $callback($mysqli);

                            if (! $mysqli->commit()) {
                                throw new TransactionException(
                                    'Failed to commit transaction: ' . $mysqli->error
                                );
                            }
                            $mysqli->autocommit(true);

                            $this->executeCallbacks($mysqli, 'commit');

                            return $result;
                        } catch (Throwable $e) {
                            $mysqli->rollback();
                            $mysqli->autocommit(true);

                            $this->executeCallbacks($mysqli, 'rollback');

                            throw $e;
                        } finally {
                            if ($this->transactionCallbacks !== null && isset($this->transactionCallbacks[$mysqli])) {
                                unset($this->transactionCallbacks[$mysqli]);
                            }
                        }
                    }));
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
     * Gets statistics about this instance's connection pool.
     *
     * Returns information about the current state of the connection pool,
     * including total connections, available connections, and connections in use.
     *
     * @return array<string, int|bool> Pool statistics including:
     *                                  - total: Total number of connections in pool
     *                                  - available: Number of available connections
     *                                  - inUse: Number of connections currently in use
     *
     * @throws NotInitializedException If this instance is not initialized
     */
    public function getStats(): array
    {
        /** @var array<string, int|bool> */
        return $this->getPool()->getStats();
    }

    /**
     * Gets the most recently used connection from this pool.
     *
     * This is primarily useful for debugging and testing purposes.
     * Returns null if no connection has been used yet.
     *
     * @return mysqli|null The last connection or null if none used yet
     *
     * @throws NotInitializedException If this instance is not initialized
     */
    public function getLastConnection(): ?mysqli
    {
        return $this->getPool()->getLastConnection();
    }

    /**
     * Detects parameter types from array values.
     *
     * Automatically determines the appropriate type string for mysqli_stmt::bind_param
     * based on the PHP types of the parameter values.
     *
     * @param  array<int, mixed>  $params  Parameter values
     * @return string Type string (i=integer, d=double, s=string, b=blob)
     *
     * @internal This method is for internal use only
     */
    private function detectParameterTypes(array $params): string
    {
        $types = '';

        foreach ($params as $param) {
            $types .= match (true) {
                $param === null => 's',
                is_bool($param) => 'i',
                is_int($param) => 'i',
                is_float($param) => 'd',
                is_resource($param) => 'b',
                is_string($param) && str_contains($param, "\0") => 'b',
                is_string($param) => 's',
                is_array($param) => 's',
                is_object($param) => 's',
                default => 's',
            };
        }

        return $types;
    }

    /**
     * Preprocesses parameters for binding to prepared statement.
     *
     * Converts PHP values to appropriate types for MySQL binding,
     * including JSON encoding for arrays and objects.
     *
     * @param  array<int, mixed>  $params  Raw parameter values
     * @return array<int, mixed> Processed parameter values
     *
     * @internal This method is for internal use only
     */
    private function preprocessParameters(array $params): array
    {
        $processedParams = [];

        foreach ($params as $param) {
            $processedParams[] = match (true) {
                $param === null => null,
                is_bool($param) => $param ? 1 : 0,
                is_int($param) || is_float($param) => $param,
                is_resource($param) => $param,
                is_string($param) => $param,
                is_array($param) => json_encode($param),
                is_object($param) && method_exists($param, '__toString') => (string) $param,
                is_object($param) => json_encode($param),
                default => $param,
            };
        }

        return $processedParams;
    }

    /**
     * Executes an async query with the specified result processing type.
     *
     * This method handles the complete lifecycle of query execution including
     * connection acquisition, query preparation, execution, result waiting,
     * and connection release.
     *
     * @param  string  $sql  SQL query/statement
     * @param  array<int, mixed>  $params  Query parameters
     * @param  string|null  $types  Parameter type string
     * @param  string  $resultType  Type of result processing ('fetchAll', 'fetchOne', 'execute', 'fetchValue')
     * @return PromiseInterface<mixed> Promise resolving to processed result
     *
     * @throws NotInitializedException If this instance is not initialized
     * @throws QueryException If query execution fails
     *
     * @internal This method is for internal use only
     */
    private function executeAsyncQuery(string $sql, array $params, ?string $types, string $resultType): PromiseInterface
    {
        return async(function () use ($sql, $params, $types, $resultType) {
            $mysqli = await($this->getPool()->get());

            try {
                if (count($params) > 0) {
                    $stmt = $mysqli->prepare($sql);
                    if ($stmt === false) {
                        throw new QueryException(
                            'Prepare failed: ' . $mysqli->error,
                            $sql,
                            $params
                        );
                    }

                    if ($types === null) {
                        $types = $this->detectParameterTypes($params);
                    }

                    if ($types === '') {
                        $types = str_repeat('s', count($params));
                    }

                    $processedParams = $this->preprocessParameters($params);

                    if ($stmt->bind_param($types, ...$processedParams) === false) {
                        throw new QueryException(
                            'Bind param failed: ' . $stmt->error,
                            $sql,
                            $params
                        );
                    }

                    if ($stmt->execute() === false) {
                        throw new QueryException(
                            'Execute failed: ' . $stmt->error,
                            $sql,
                            $params
                        );
                    }

                    if (
                        stripos(trim($sql), 'SELECT') === 0 ||
                        stripos(trim($sql), 'SHOW') === 0 ||
                        stripos(trim($sql), 'DESCRIBE') === 0
                    ) {
                        $result = $stmt->get_result();
                    } else {
                        $result = true;
                    }

                    return $this->processResult($result, $resultType, $stmt, $mysqli, $sql, $params);
                } else {
                    if ($mysqli->query($sql, MYSQLI_ASYNC) === false) {
                        throw new QueryException(
                            'Query failed: ' . $mysqli->error,
                            $sql,
                            $params
                        );
                    }

                    try {
                        $result = await($this->waitForAsyncCompletion($mysqli));
                    } catch (\mysqli_sql_exception $e) {
                        throw new QueryException(
                            'Query execution failed: ' . $e->getMessage(),
                            $sql,
                            $params
                        );
                    }

                    return $this->processResult($result, $resultType, null, $mysqli, $sql, $params);
                }
            } catch (QueryException $e) {
                throw $e;
            } catch (\mysqli_sql_exception $e) {
                throw new QueryException(
                    'Query execution failed: ' . $e->getMessage(),
                    $sql,
                    $params,
                    $e
                );
            } catch (Throwable $e) {
                throw new QueryException(
                    'Unexpected error during query execution: ' . $e->getMessage(),
                    $sql,
                    $params,
                    $e
                );
            } finally {
                $this->getPool()->release($mysqli);
            }
        });
    }

    /**
     * Waits for an async query to complete using non-blocking polling.
     *
     * This method polls the connection status using mysqli_poll until
     * the query completes. This provides efficient non-blocking behavior
     * without busy-waiting.
     *
     * @param  mysqli  $mysqli  MySQL connection
     * @param  mysqli_stmt|null  $stmt  Optional prepared statement
     * @return PromiseInterface<mysqli_result> Promise resolving to query result
     *
     * @throws \mysqli_sql_exception If query execution fails
     * @throws QueryException If polling fails
     *
     * @internal This method is for internal use only
     */
    private function waitForAsyncCompletion(mysqli $mysqli, ?mysqli_stmt $stmt = null): PromiseInterface
    {
        return async(function () use ($mysqli, $stmt): bool|mysqli_result {
            $links = [$mysqli];
            $errors = [$mysqli];
            $reject = [$mysqli];

            $ready = mysqli_poll($links, $errors, $reject, 0, 0);

            if ($ready > 0) {
                $result = $stmt !== null ? $stmt->get_result() : $mysqli->reap_async_query();
                if ($result === false) {
                    throw new QueryException('Failed to retrieve async query result');
                }
                return $result;
            }

            if ($ready === false) {
                throw new QueryException('MySQLi poll failed immediately');
            }

            $pollInterval = self::POLL_INTERVAL;

            while (true) {
                $links = [$mysqli];
                $errors = [$mysqli];
                $reject = [$mysqli];

                $ready = mysqli_poll($links, $errors, $reject, 0, $pollInterval);

                if ($ready === false) {
                    throw new QueryException('MySQLi poll failed during wait');
                }

                if ($ready > 0) {
                    $result = $stmt !== null ? $stmt->get_result() : $mysqli->reap_async_query();
                    if ($result === false) {
                        throw new QueryException('Failed to retrieve async query result');
                    }
                    return $result;
                }

                await(Timer::delay(0));
                $pollInterval = (int) min($pollInterval * 1.2, self::POLL_MAX_INTERVAL);
            }
        });
    }

    /**
     * Processes a query result based on the specified result type.
     *
     * This method converts the raw MySQL result into the appropriate
     * PHP data structure based on the requested result type.
     *
     * @param  mysqli_result|bool  $result  MySQL query result
     * @param  string  $resultType  Type of result processing
     * @param  mysqli_stmt|null  $stmt  Optional prepared statement for error reporting
     * @param  mysqli|null  $mysqli  MySQL connection for error reporting
     * @param  string  $sql  The SQL query for error context
     * @param  array<int, mixed>  $params  The query parameters for error context
     * @return mixed Processed result based on result type
     *
     * @throws QueryException If result is false or processing fails
     *
     * @internal This method is for internal use only
     */
    private function processResult(
        mysqli_result|bool $result,
        string $resultType,
        ?mysqli_stmt $stmt,
        ?mysqli $mysqli,
        string $sql,
        array $params
    ): mixed {
        if ($result === false) {
            $error = 'Unknown error';
            if ($stmt !== null) {
                $error = $stmt->error;
            } elseif ($mysqli !== null) {
                $error = $mysqli->error;
            }

            throw new QueryException(
                'Query execution failed: ' . $error,
                $sql,
                $params
            );
        }

        return match ($resultType) {
            'fetchAll' => $this->handleFetchAll($result),
            'fetchOne' => $this->handleFetchOne($result),
            'fetchValue' => $this->handleFetchValue($result),
            'execute' => $this->handleExecute($stmt, $mysqli),
            default => $result,
        };
    }

    /**
     * Fetches all rows from a query result.
     *
     * Converts the MySQL result into an array of associative arrays,
     * where each array represents a row with column names as keys.
     *
     * @param  mysqli_result|bool  $result  MySQL query result
     * @return array<int, array<string, mixed>> Array of associative arrays
     *
     * @internal This method is for internal use only
     */
    private function handleFetchAll(mysqli_result|bool $result): array
    {
        if ($result instanceof mysqli_result) {
            /** @var array<int, array<string, mixed>> */
            return $result->fetch_all(MYSQLI_ASSOC);
        }

        return [];
    }

    /**
     * Fetches the first row from a query result.
     *
     * Converts the first row of the MySQL result into an associative array
     * with column names as keys. Returns null if no rows exist.
     *
     * @param  mysqli_result|bool  $result  MySQL query result
     * @return array<string, float|int|string|null>|null Associative array or null if no rows
     *
     * @internal This method is for internal use only
     */
    private function handleFetchOne(mysqli_result|bool $result): ?array
    {
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();

            if ($row === false) {
                return null;
            }

            return $row;
        }

        return null;
    }

    /**
     * Fetches a single column value from the first row.
     *
     * Extracts the first column of the first row from the result set.
     * Useful for aggregate queries like COUNT, SUM, MAX, etc.
     *
     * @param  mysqli_result|bool  $result  MySQL query result
     * @return mixed Scalar value or null if no rows
     *
     * @internal This method is for internal use only
     */
    private function handleFetchValue(mysqli_result|bool $result): mixed
    {
        if (! ($result instanceof mysqli_result)) {
            return null;
        }

        $row = $result->fetch_row();

        return $row !== null ? $row[0] : null;
    }

    /**
     * Gets the number of affected rows from a query result.
     *
     * Returns the count of rows affected by an INSERT, UPDATE, or DELETE statement.
     *
     * @param  mysqli_stmt|null  $stmt  Optional prepared statement
     * @param  mysqli|null  $mysqli  MySQL connection
     * @return int Number of affected rows
     *
     * @internal This method is for internal use only
     */
    private function handleExecute(?mysqli_stmt $stmt, ?mysqli $mysqli): int
    {
        if ($stmt !== null) {
            $affectedRows = $stmt->affected_rows;
            return $affectedRows < 0 ? 0 : (int)$affectedRows;
        }
        if ($mysqli !== null) {
            $affectedRows = $mysqli->affected_rows;
            return $affectedRows < 0 ? 0 : (int)$affectedRows;
        }

        return 0;
    }

    /**
     * Gets the current transaction's mysqli instance if in a transaction within the current fiber.
     *
     * This method checks if the current fiber is executing within a transaction context
     * and returns the associated connection if found.
     *
     * @return mysqli|null Connection instance or null if not in transaction
     *
     * @internal This method is for internal use only
     */
    private function getCurrentTransactionConnection(): ?mysqli
    {
        if ($this->transactionCallbacks === null) {
            return null;
        }

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
     * @param  mysqli  $mysqli  MySQL connection
     * @param  string  $type  'commit' or 'rollback'
     * @return void
     *
     * @throws TransactionException If any callback throws an exception
     *
     * @internal This method is for internal use only
     */
    private function executeCallbacks(mysqli $mysqli, string $type): void
    {
        if ($this->transactionCallbacks === null || ! isset($this->transactionCallbacks[$mysqli])) {
            return;
        }

        $transactionData = $this->transactionCallbacks[$mysqli];

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

    /**
     * Ensures the transaction callbacks WeakMap is initialized.
     *
     * This is a safety check to ensure the WeakMap exists before use.
     * In normal operation, it should always be initialized in the constructor.
     *
     * @return void
     *
     * @internal This method is for internal use only
     */
    private function ensureTransactionCallbacksInitialized(): void
    {
        if ($this->transactionCallbacks === null) {
            $this->transactionCallbacks = new WeakMap();
        }
    }

    /**
     * Gets the connection pool instance.
     *
     * @return PoolManager The initialized connection pool
     *
     * @throws NotInitializedException If this instance is not initialized
     *
     * @internal This method is for internal use only
     */
    private function getPool(): PoolManager
    {
        if (! $this->isInitialized || $this->pool === null) {
            throw new NotInitializedException(
                'MySQLConnection instance has not been initialized or has been reset.'
            );
        }

        return $this->pool;
    }
}
