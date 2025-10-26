<?php

namespace Hibla\Mysql\Manager;

use InvalidArgumentException;
use mysqli;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use RuntimeException;
use SplQueue;
use Throwable;

/**
 * An asynchronous, fiber-aware MySQLi connection pool.
 *
 * This class manages a pool of MySQLi connections to provide efficient, non-blocking
 * database access in an asynchronous environment. It handles connection limits,
 * waiting queues, and safe connection reuse.
 */
class PoolManager
{
    /**
     * @var SplQueue<mysqli> A queue of available, idle connections.
     */
    private SplQueue $pool;

    /**
     * @var SplQueue<Promise<mysqli>> A queue of pending requests for a connection.
     */
    private SplQueue $waiters;

    /**
     * @var int The maximum number of concurrent connections.
     */
    private int $maxSize;

    /**
     * @var int The current number of active connections.
     */
    private int $activeConnections = 0;

    /**
     * @var array<string, mixed> The database connection configuration.
     */
    private array $dbConfig;

    /**
     * @var mysqli|null The most recently used or created connection.
     */
    private ?mysqli $lastConnection = null;

    /**
     * @var bool Flag indicating if the configuration has been validated.
     */
    private bool $configValidated = false;

    /**
     * Creates a new MySQLi connection pool.
     *
     * @param  array<string, mixed>  $dbConfig  Database configuration array.
     * @param  int  $maxSize  Maximum number of concurrent connections.
     *
     * @throws InvalidArgumentException If the configuration is invalid.
     */
    public function __construct(array $dbConfig, int $maxSize = 10)
    {
        $this->validateDbConfig($dbConfig);
        $this->configValidated = true;
        $this->dbConfig = $dbConfig;
        $this->maxSize = $maxSize;
        $this->pool = new SplQueue;
        $this->waiters = new SplQueue;
    }

    /**
     * Asynchronously acquires a MySQLi connection from the pool.
     *
     * @return PromiseInterface<mysqli> A promise that resolves with a mysqli connection object.
     */
    public function get(): PromiseInterface
    {
        if (! $this->pool->isEmpty()) {
            /** @var mysqli $connection */
            $connection = $this->pool->dequeue();
            $this->lastConnection = $connection;

            /** @var PromiseInterface<mysqli> $promise */
            $promise = Promise::resolved($connection);

            return $promise;
        }

        if ($this->activeConnections < $this->maxSize) {
            $this->activeConnections++;

            try {
                $connection = $this->createConnection();
                $this->lastConnection = $connection;

                /** @var PromiseInterface<mysqli> $promise */
                $promise = Promise::resolved($connection);

                return $promise;
            } catch (Throwable $e) {
                $this->activeConnections--;
                /** @var PromiseInterface<mysqli> $promise */
                $promise = Promise::rejected($e);

                return $promise;
            }
        }

        /** @var Promise<mysqli> $promise */
        $promise = new Promise;
        $this->waiters->enqueue($promise);

        return $promise;
    }

    /**
     * Releases a MySQLi connection back to the pool for reuse.
     *
     * @param  mysqli  $connection  The MySQLi connection to release.
     */
    public function release(mysqli $connection): void
    {
        if (! $this->isConnectionAlive($connection)) {
            $this->activeConnections--;
            if (! $this->waiters->isEmpty() && $this->activeConnections < $this->maxSize) {
                $this->activeConnections++;
                /** @var Promise<mysqli> $promise */
                $promise = $this->waiters->dequeue();

                try {
                    $newConnection = $this->createConnection();
                    $this->lastConnection = $newConnection;
                    $promise->resolve($newConnection);
                } catch (Throwable $e) {
                    $this->activeConnections--;
                    $promise->reject($e);
                }
            }

            return;
        }

        $this->resetConnectionState($connection);

        if (! $this->waiters->isEmpty()) {
            /** @var Promise<mysqli> $promise */
            $promise = $this->waiters->dequeue();
            $this->lastConnection = $connection;
            $promise->resolve($connection);
        } else {
            $this->pool->enqueue($connection);
        }
    }

    /**
     * Gets the most recently active connection handled by the pool.
     */
    public function getLastConnection(): ?mysqli
    {
        return $this->lastConnection;
    }

    /**
     * Retrieves statistics about the current state of the connection pool.
     *
     * @return array<string, int|bool> An associative array with pool metrics.
     */
    public function getStats(): array
    {
        return [
            'active_connections' => $this->activeConnections,
            'pooled_connections' => $this->pool->count(),
            'waiting_requests' => $this->waiters->count(),
            'max_size' => $this->maxSize,
            'config_validated' => $this->configValidated,
        ];
    }

    /**
     * Closes all connections and clears the pool.
     */
    public function close(): void
    {
        while (! $this->pool->isEmpty()) {
            /** @var mysqli $connection */
            $connection = $this->pool->dequeue();
            if ($this->isConnectionAlive($connection)) {
                $connection->close();
            }
        }
        while (! $this->waiters->isEmpty()) {
            /** @var Promise<mysqli> $promise */
            $promise = $this->waiters->dequeue();
            $promise->reject(new RuntimeException('Pool is being closed'));
        }
        $this->pool = new SplQueue;
        $this->waiters = new SplQueue;
        $this->activeConnections = 0;
        $this->lastConnection = null;
    }

    /**
     * Validates the database configuration.
     *
     * @param  array<string, mixed>  $dbConfig
     *
     * @throws InvalidArgumentException
     */
    private function validateDbConfig(array $dbConfig): void
    {
        if (count($dbConfig) === 0) {
            throw new InvalidArgumentException('Database configuration cannot be empty');
        }
        $requiredFields = ['host', 'username', 'database'];
        foreach ($requiredFields as $field) {
            if (! array_key_exists($field, $dbConfig)) {
                throw new InvalidArgumentException("Missing required database configuration field: '{$field}'");
            }
            if (in_array($field, ['host', 'database'], true) && ($dbConfig[$field] === '' || $dbConfig[$field] === null)) {
                throw new InvalidArgumentException("Database configuration field '{$field}' cannot be empty");
            }
        }
        if (isset($dbConfig['port']) && (! is_int($dbConfig['port']) || $dbConfig['port'] <= 0)) {
            throw new InvalidArgumentException('Database port must be a positive integer');
        }
        if (isset($dbConfig['host']) && ! is_string($dbConfig['host'])) {
            throw new InvalidArgumentException('Database host must be a string');
        }
        if (isset($dbConfig['charset']) && ! is_string($dbConfig['charset'])) {
            throw new InvalidArgumentException('Database charset must be a string');
        }
        if (isset($dbConfig['socket']) && ! is_string($dbConfig['socket'])) {
            throw new InvalidArgumentException('Database socket must be a string');
        }
    }

    /**
     * Creates a new, configured MySQLi connection.
     *
     * @throws RuntimeException
     */
    private function createConnection(): mysqli
    {
        $config = $this->dbConfig;

        $host = isset($config['host']) && is_string($config['host']) ? $config['host'] : null;
        $username = isset($config['username']) && is_string($config['username']) ? $config['username'] : null;
        $password = isset($config['password']) && is_string($config['password']) ? $config['password'] : null;
        $database = isset($config['database']) && is_string($config['database']) ? $config['database'] : null;
        $port = isset($config['port']) && is_int($config['port']) ? $config['port'] : null;
        $socket = isset($config['socket']) && is_string($config['socket']) ? $config['socket'] : null;

        $mysqli = new mysqli($host, $username, $password, $database, $port, $socket);

        if ($mysqli->connect_error !== null) {
            throw new RuntimeException('MySQLi Connection failed: '.$mysqli->connect_error);
        }

        if (isset($config['charset']) && is_string($config['charset'])) {
            if (! $mysqli->set_charset($config['charset'])) {
                throw new RuntimeException('Failed to set charset: '.$mysqli->error);
            }
        }

        return $mysqli;
    }

    /**
     * Checks if a MySQLi connection is still alive using a lightweight query.
     */
    private function isConnectionAlive(mysqli $connection): bool
    {
        try {
            return $connection->query('SELECT 1') !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Resets connection state to clean it for reuse.
     */
    private function resetConnectionState(mysqli $connection): void
    {
        try {
            while ($connection->more_results() && $connection->next_result()) {
                $result = $connection->store_result();
                if ($result !== false) {
                    $result->free();
                }
            }

            $connection->autocommit(true);
        } catch (Throwable $e) {
            // If reset fails, isConnectionAlive() will catch it on the next cycle.
        }
    }
}
