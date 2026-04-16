<?php

declare(strict_types=1);

namespace Hibla\Mysql\Internals;

use Hibla\Mysql\Interfaces\MysqlRowStream;
use Hibla\Mysql\ValueObjects\StreamStats;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use SplQueue;
use Throwable;

use function Hibla\await;

/**
 * Provides an asynchronous stream of rows using PHP Generators.
 *
 * @internal This must not be used directly.
 */
class RowStream implements MysqlRowStream
{
    /**
     * Default maximum number of rows to buffer before applying backpressure.
     */
    private const int DEFAULT_BUFFER_SIZE = 100;

    /**
     * @var SplQueue<array<string, mixed>>
     */
    private SplQueue $buffer;

    /**
     * @var array<int, string>
     */
    private array $columnNames = [];

    /**
     * @var Promise<array<string, mixed>|null>|null
     */
    private ?Promise $waiter = null;

    /**
     * @var Promise<void>
     */
    private Promise $internalLifecyclePromise;

    /**
     * @var PromiseInterface<mixed>|null
     */
    private ?PromiseInterface $internalCommandQueuePromise = null;

    /**
     * @var (\Closure(bool): void)|null
     */
    private ?\Closure $onBackpressure = null;

    private ?StreamStats $internalStats = null;

    private ?Throwable $error = null;

    private bool $completed = false;

    private bool $cancelled = false;

    private int $maxBufferSize;

    private int $resumeThreshold;

    /**
     * The number of columns in the streaming result set.
     */
    public int $columnCount {
        get => \count($this->columnNames);
    }

    /**
     * The column names in the streaming result set.
     *
     * @var array<int, string>
     */
    public array $columns {
        get => $this->columnNames;
        set {
            $this->columnNames = $value;
        }
    }

    /**
     * Statistics about the completed stream, or null if still in progress.
     */
    public ?StreamStats $stats {
        get => $this->internalStats;
    }

    /**
     * The command queue promise used to propagate cancellation (KILL QUERY).
     *
     * @internal
     */
    public ?PromiseInterface $commandQueuePromise {
        set {
            $this->internalCommandQueuePromise = $value;
        }
    }

    /**
     * The backpressure handler for controlling socket flow.
     *
     * @internal
     *
     * @var (\Closure(bool): void)|null
     */
    public ?\Closure $backpressureHandler {
        set {
            $this->onBackpressure = $value;
        }
    }

    public function __construct(int $bufferSize = self::DEFAULT_BUFFER_SIZE)
    {
        if ($bufferSize < 1) {
            throw new \InvalidArgumentException('Buffer size must be at least 1');
        }

        $this->maxBufferSize = $bufferSize;
        $this->resumeThreshold = (int) ($bufferSize / 2);

        /** @var SplQueue<array<string, mixed>> $buffer */
        $buffer = new SplQueue();
        $this->buffer = $buffer;

        /** @var Promise<void> $lifecyclePromise */
        $lifecyclePromise = new Promise();
        $this->internalLifecyclePromise = $lifecyclePromise;
    }

    /**
     * Iterates over the rows.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function getIterator(): \Generator
    {
        while (true) {
            if ($this->error !== null) {
                throw $this->error;
            }

            if (! $this->buffer->isEmpty()) {
                $row = $this->buffer->dequeue();

                if ($this->onBackpressure !== null && $this->buffer->count() < $this->resumeThreshold) {
                    ($this->onBackpressure)(false);
                }

                yield $row;

                continue;
            }

            if ($this->completed) {
                break;
            }

            /** @var Promise<array<string, mixed>|null> $waiter */
            $waiter = new Promise();
            $this->waiter = $waiter;

            /** @var array<string, mixed>|null $row */
            $row = await($waiter);

            if ($row === null) {
                break;
            }

            yield $row;
        }
    }

    /**
     * Cancels the stream and releases resources.
     */
    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        $this->error = new CancelledException('Stream was cancelled');

        if (! $this->completed) {
            $this->completed = true;

            if (
                $this->internalCommandQueuePromise !== null
                && ! $this->internalCommandQueuePromise->isSettled()
                && ! $this->internalCommandQueuePromise->isCancelled()
            ) {
                $this->internalCommandQueuePromise->cancel();
            }

            if ($this->waiter !== null) {
                $waiter = $this->waiter;
                $this->waiter = null;
                $waiter->reject($this->error);
            }
        }

        while (! $this->buffer->isEmpty()) {
            $this->buffer->dequeue();
        }
    }

    /**
     * Returns whether this stream has been cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Pushes a row into the stream buffer.
     *
     * @internal
     */
    public function push(array $row): void
    {
        if ($this->cancelled) {
            return;
        }

        if ($this->columnNames === []) {
            $this->columnNames = array_keys($row);
        }

        if ($this->waiter !== null) {
            $promise = $this->waiter;
            $this->waiter = null;
            $promise->resolve($row);
        } else {
            $this->buffer->enqueue($row);

            if ($this->onBackpressure !== null && $this->buffer->count() >= $this->maxBufferSize) {
                ($this->onBackpressure)(true);
            }
        }
    }

    /**
     * Marks the stream as complete.
     *
     * @internal
     */
    public function complete(StreamStats $stats): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->internalStats = $stats;
        $this->completed = true;

        if ($this->waiter !== null) {
            $promise = $this->waiter;
            $this->waiter = null;
            $promise->resolve(null);
        }
    }

    /**
     * Marks the stream as failed.
     *
     * @internal
     */
    public function error(Throwable $e): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->error = $e;
        $this->completed = true;

        if ($this->waiter !== null) {
            $promise = $this->waiter;
            $this->waiter = null;
            $promise->reject($e);
        }

        if ($this->internalLifecyclePromise->isPending()) {
            $this->internalLifecyclePromise->reject($e);
        }
    }

    /**
     * Called by Connection when the command is fully finished.
     *
     * @internal
     */
    public function markCommandFinished(): void
    {
        if ($this->internalLifecyclePromise->isPending()) {
            $this->internalLifecyclePromise->resolve(null);
        }
    }

    /**
     * Returns a promise that resolves when the underlying database command is complete.
     *
     * @internal
     *
     * @return Promise<void>
     */
    public function waitForCommand(): Promise
    {
        return $this->internalLifecyclePromise;
    }
}
