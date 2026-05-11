<?php

declare(strict_types=1);

namespace Hibla\Mysql\Interfaces;

use Hibla\Mysql\ValueObjects\StreamStats;
use Hibla\Sql\RowStream;

/**
 * Provides an asynchronous stream of rows with access to MySQL-specific StreamStats.
 *
 * This interface is both a RowStream and a CancellableStream.
 */
interface MysqlRowStream extends RowStream
{
    /**
     * Statistics about the completed stream, or null if still in progress.
     */
    public ?StreamStats $stats { get; }
}
