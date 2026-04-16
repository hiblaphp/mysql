<?php

declare(strict_types=1);

namespace Hibla\Mysql\Interfaces;

use Hibla\Mysql\ValueObjects\StreamStats;
use Hibla\Sql\CancellableStreamInterface;
use Hibla\Sql\RowStream;

/**
 * Provides an asynchronous stream of rows with access to MySQL-specific StreamStats.
 *
 * This interface is both a RowStream and a CancellableStream.
 */
interface MysqlRowStream extends RowStream, CancellableStreamInterface
{
    /**
     * Statistics about the completed stream, or null if still in progress.
     */
    public ?StreamStats $stats { get; }

    /**
     * Cancels the stream and releases resources.
     *
     * Phase 1 (pre-resolution): If called before await($streamPromise) returns,
     * the underlying command promise is cancelled and KILL QUERY is dispatched
     * to MySQL.
     *
     * Phase 2 (mid-iteration): If called during foreach iteration after the
     * promise has already resolved, the stream is marked cancelled and buffered
     * rows are discarded. KILL QUERY is only dispatched if the command is still in-flight.
     */
    public function cancel(): void;

    /**
     * Returns whether this stream has been cancelled.
     */
    public function isCancelled(): bool;
}
