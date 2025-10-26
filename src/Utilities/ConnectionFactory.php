<?php

declare(strict_types=1);

namespace Hibla\MySQL\Utilities;

use Fasync\Mysql\Exceptions\PoolException;
use mysqli;
use mysqli_sql_exception;
use RuntimeException;

/**
 * Factory for creating and configuring MySQLi connections.
 */
final class ConnectionFactory
{
    /**
     * Creates a new MySQLi connection from configuration.
     *
     * @param  array<string, mixed>  $config  Database configuration.
     * @return mysqli The newly created and configured connection.
     *
     * @throws RuntimeException If connection or configuration fails.
     */
    public static function create(array $config): mysqli
    {
        $mysqli = self::createConnection($config);
        self::applyOptions($mysqli, $config);
        self::setCharset($mysqli, $config);

        return $mysqli;
    }

    /**
     * Creates a new MySQLi connection with basic parameters.
     *
     * @param  array<string, mixed>  $config  Database configuration.
     * @return mysqli The newly created connection.
     *
     * @throws PoolException If connection fails.
     */
    private static function createConnection(array $config): mysqli
    {
        $host = self::getStringOrNull($config, 'host');
        $username = self::getStringOrNull($config, 'username');
        $password = self::getStringOrNull($config, 'password');
        $database = self::getStringOrNull($config, 'database');
        $port = self::getIntOrNull($config, 'port');
        $socket = self::getStringOrNull($config, 'socket');

        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $mysqli = new mysqli($host, $username, $password, $database, $port, $socket);
        } catch (mysqli_sql_exception $e) {
            throw new PoolException('MySQLi Connection failed: ' . $e->getMessage(), 0, $e);
        }

        if ($mysqli->connect_error !== null) {
            throw new PoolException('MySQLi Connection failed: ' . $mysqli->connect_error);
        }

        return $mysqli;
    }

    /**
     * Applies mysqli options to the connection.
     *
     * @param  mysqli  $mysqli  The MySQLi connection.
     * @param  array<string, mixed>  $config  Database configuration.
     * @return void
     *
     * @throws PoolException If setting an option fails.
     */
    private static function applyOptions(mysqli $mysqli, array $config): void
    {
        if (! isset($config['options']) || ! is_array($config['options'])) {
            return;
        }

        foreach ($config['options'] as $option => $value) {
            if (!is_int($value) && !is_string($value)) {
                throw new PoolException(
                    sprintf(
                        'Invalid option value type for %s: expected int or string, got %s',
                        $option,
                        get_debug_type($value)
                    )
                );
            }

            if (! $mysqli->options($option, $value)) {
                throw new PoolException(
                    sprintf('Failed to set mysqli option %s: %s', $option, $mysqli->error)
                );
            }
        }
    }

    /**
     * Sets the character set for the connection.
     *
     * @param  mysqli  $mysqli  The MySQLi connection.
     * @param  array<string, mixed>  $config  Database configuration.
     * @return void
     *
     * @throws PoolException If setting charset fails.
     */
    private static function setCharset(mysqli $mysqli, array $config): void
    {
        if (! isset($config['charset']) || ! is_string($config['charset'])) {
            return;
        }

        try {
            if (! $mysqli->set_charset($config['charset'])) {
                throw new PoolException('Failed to set charset: ' . $mysqli->error);
            }
        } catch (mysqli_sql_exception $e) {
            throw new PoolException('Failed to set charset: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Safely gets a string value from config or returns null.
     *
     * @param  array<string, mixed>  $config  Configuration array.
     * @param  string  $key  Configuration key.
     * @return string|null The string value or null.
     */
    private static function getStringOrNull(array $config, string $key): ?string
    {
        return isset($config[$key]) && is_string($config[$key]) ? $config[$key] : null;
    }

    /**
     * Safely gets an integer value from config or returns null.
     *
     * @param  array<string, mixed>  $config  Configuration array.
     * @param  string  $key  Configuration key.
     * @return int|null The integer value or null.
     */
    private static function getIntOrNull(array $config, string $key): ?int
    {
        return isset($config[$key]) && is_int($config[$key]) ? $config[$key] : null;
    }
}
