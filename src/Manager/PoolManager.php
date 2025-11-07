<?php

declare(strict_types=1);

namespace Hibla\MySQL\Manager;

use Hibla\MySQL\Exceptions\PoolException;
use Hibla\MySQL\Utilities\ConfigValidator;
use Hibla\MySQL\Utilities\ConnectionFactory;
use Hibla\MySQL\Utilities\ConnectionHealthChecker;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use InvalidArgumentException;
use mysqli;
use SplQueue;
use Throwable;

/**
 * An asynchronous, fiber-aware MySQLi connection pool.
 *
 * This class manages a pool of MySQLi connections to provide efficient, non-blocking
 * database access in an asynchronous environment. It handles connection limits,
 * waiting queues, and safe connection reuse.
 *
 * Note: When using persistent connections (persistent => true), PHP's built-in
 * mysqli persistent connection mechanism is used, which pools connections per PHP
 * process. This pool manager still provides value by managing connection limits
 * and async access patterns within your application.
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
     * @var bool Whether to use persistent connections (mysqli with 'p:' prefix).
     */
    private bool $persistent = false;

    /**
     * Creates a new MySQLi connection pool.
     *
     * @param  array<string, mixed>  $dbConfig  Database configuration array.
     *                                          Can include 'persistent' => true to enable persistent connections.
     *
     *                                          Note: Persistent connections are pooled by PHP per-process based on
     *                                          host, username, password, socket, port, and database. Each unique
     *                                          combination creates a separate persistent connection pool at the
     *                                          PHP level.
     * @param  int  $maxSize  Maximum number of concurrent connections.
     *
     * @throws InvalidArgumentException If the configuration is invalid or pool size is less than 1.
     */
    public function __construct(array $dbConfig, int $maxSize = 10)
    {
        $this->validatePoolSize($maxSize);
        ConfigValidator::validate($dbConfig);
        $this->configValidated = true;

        $this->persistent = (bool) ($dbConfig['persistent'] ?? false);
        unset($dbConfig['persistent']);

        $this->dbConfig = $dbConfig;
        $this->maxSize = $maxSize;
        $this->pool = new SplQueue();
        $this->waiters = new SplQueue();
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
        $promise = new Promise();
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
        if (! ConnectionHealthChecker::isAlive($connection)) {
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

        ConnectionHealthChecker::reset($connection);

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
     *
     * @return mysqli|null The last connection or null if none has been used yet.
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
            'persistent' => $this->persistent,
        ];
    }

    /**
     * Checks if persistent connections are enabled.
     *
     * When enabled, PHP's built-in mysqli persistent connection mechanism is used.
     * Persistent connections are pooled per PHP process and reused across requests
     * based on the combination of host, username, password, socket, port, and database.
     *
     * @return bool True if using persistent connections.
     */
    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    /**
     * Closes all connections and clears the pool.
     *
     * Note: For persistent connections, mysqli::close() returns the connection to
     * PHP's persistent connection pool rather than actually closing it. The connection
     * may be reused by other parts of the application or future requests.
     *
     * @return void
     */
    public function close(): void
    {
        while (! $this->pool->isEmpty()) {
            /** @var mysqli $connection */
            $connection = $this->pool->dequeue();
            if (ConnectionHealthChecker::isAlive($connection)) {
                $connection->close();
            }
        }
        while (! $this->waiters->isEmpty()) {
            /** @var Promise<mysqli> $promise */
            $promise = $this->waiters->dequeue();
            $promise->reject(new PoolException('Pool is being closed'));
        }
        $this->pool = new SplQueue();
        $this->waiters = new SplQueue();
        $this->activeConnections = 0;
        $this->lastConnection = null;
    }

    /**
     * Validates the pool size.
     *
     * @param  int  $maxSize  The maximum pool size to validate.
     *
     * @throws InvalidArgumentException If pool size is less than 1.
     */
    private function validatePoolSize(int $maxSize): void
    {
        if ($maxSize < 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'Pool size must be at least 1, got %d',
                    $maxSize
                )
            );
        }
    }

    /**
     * Creates a new MySQLi connection.
     *
     * For persistent connections, the 'p:' prefix is added to the hostname
     * which triggers PHP's built-in persistent connection mechanism. PHP will
     * automatically handle connection pooling and cleanup (via mysqli_change_user)
     * before returning connections from its persistent pool.
     *
     * @return mysqli The newly created connection.
     *
     * @throws PoolException If connection fails.
     */
    private function createConnection(): mysqli
    {
        return ConnectionFactory::create($this->dbConfig, $this->persistent);
    }
}
