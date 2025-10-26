<?php

declare(strict_types=1);

namespace Hibla\MySQL\Utilities;

use mysqli;
use Throwable;

/**
 * Checks and maintains MySQLi connection health.
 */
final class ConnectionHealthChecker
{
    /**
     * Checks if a MySQLi connection is still alive.
     *
     * @param  mysqli  $connection  The connection to check.
     * @return bool True if the connection is alive, false otherwise.
     */
    public static function isAlive(mysqli $connection): bool
    {
        try {
            // First clear any pending results
            self::clearPendingResults($connection);
            
            return $connection->query('SELECT 1') !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Resets connection state to prepare it for reuse.
     *
     * Clears any pending results and ensures autocommit is enabled.
     *
     * @param  mysqli  $connection  The connection to reset.
     * @return void
     */
    public static function reset(mysqli $connection): void
    {
        try {
            self::clearPendingResults($connection);
            $connection->autocommit(true);
        } catch (Throwable $e) {
            // If reset fails, isAlive() will catch it on the next cycle.
        }
    }

    /**
     * Clears any pending results from multi-query execution.
     *
     * @param  mysqli  $connection  The connection to clear.
     * @return void
     */
    private static function clearPendingResults(mysqli $connection): void
    {
        // Clear any stored results
        while ($connection->more_results()) {
            $connection->next_result();
            $result = $connection->store_result();
            if ($result instanceof \mysqli_result) {
                $result->free();
            }
        }
    }
}