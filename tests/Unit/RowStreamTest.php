<?php

declare(strict_types=1);

namespace Hibla\Mysql\Tests\Internals;

use Hibla\Mysql\Internals\RowStream;
use Hibla\Mysql\ValueObjects\StreamStats;
use Hibla\Promise\Promise;
use Mockery;

use function Hibla\await;

describe('RowStream', function (): void {

    it('throws InvalidArgumentException when buffer size is less than 1', function (): void {
        expect(fn () => new RowStream(0))->toThrow(\InvalidArgumentException::class, 'Buffer size must be at least 1');
        expect(fn () => new RowStream(-10))->toThrow(\InvalidArgumentException::class, 'Buffer size must be at least 1');
    });

    it('exposes zero columns and columnCount before any row is pushed', function (): void {
        $stream = new RowStream();

        expect($stream->columns)->toBe([])
            ->and($stream->columnCount)->toBe(0)
        ;
    });

    it('derives column names from the first pushed row', function (): void {
        $stream = new RowStream();
        $stream->push(['id' => 1, 'name' => 'Alice']);

        expect($stream->columns)->toBe(['id', 'name'])
            ->and($stream->columnCount)->toBe(2)
        ;
    });

    it('does not overwrite column names after the first push', function (): void {
        $stream = new RowStream();
        $stream->push(['id' => 1]);
        $stream->push(['id' => 2, 'extra' => 'x']);

        expect($stream->columns)->toBe(['id'])
            ->and($stream->columnCount)->toBe(1)
        ;
    });

    it('starts with null stats and stores them after complete() is called', function (): void {
        $stream = new RowStream();
        expect($stream->stats)->toBeNull();

        $stats = Mockery::mock(StreamStats::class);
        $stream->complete($stats);

        expect($stream->stats)->toBe($stats);
    });

    it('reports isCancelled() as false initially and true after cancel()', function (): void {
        $stream = new RowStream();
        expect($stream->isCancelled())->toBeFalse();

        $stream->cancel();
        expect($stream->isCancelled())->toBeTrue();
    });

    it('is idempotent when cancel() is called multiple times', function (): void {
        $stream = new RowStream();
        $stream->cancel();
        $stream->cancel();

        expect($stream->isCancelled())->toBeTrue();
    });

    it('ignores push() after cancellation', function (): void {
        $stream = new RowStream();
        $stream->cancel();
        $stream->push(['id' => 1]);

        expect($stream->columns)->toBe([])
            ->and($stream->columnCount)->toBe(0)
        ;
    });

    it('ignores complete() after cancellation', function (): void {
        $stream = new RowStream();
        $stream->cancel();
        $stream->complete(Mockery::mock(StreamStats::class));

        expect($stream->stats)->toBeNull();
    });

    it('cancels the command queue promise when an in-flight stream is cancelled', function (): void {
        $stream         = new RowStream();
        $commandPromise = new Promise();
        $stream->commandQueuePromise = $commandPromise;

        $stream->cancel();

        expect($commandPromise->isCancelled())->toBeTrue();
    });

    it('triggers pause backpressure when buffer reaches max capacity', function (): void {
        $stream = new RowStream(bufferSize: 3);
        $paused = false;

        $stream->backpressureHandler = function (bool $pause) use (&$paused): void {
            $paused = $pause;
        };

        $stream->push(['id' => 1]);
        $stream->push(['id' => 2]);
        expect($paused)->toBeFalse();

        $stream->push(['id' => 3]); 
        expect($paused)->toBeTrue();
    });

    it('triggers resume backpressure when iterating drains buffer below threshold', function (): void {
        $stream  = new RowStream(bufferSize: 4);
        $resumed = false;

        $stream->backpressureHandler = function (bool $pause) use (&$resumed): void {
            if (! $pause) {
                $resumed = true;
            }
        };

        $stream->push(['id' => 1]);
        $stream->push(['id' => 2]);
        $stream->push(['id' => 3]);
        $stream->push(['id' => 4]);
        $stream->complete(Mockery::mock(StreamStats::class));

        foreach ($stream as $_) {
        }

        expect($resumed)->toBeTrue();
    });

    it('resolves waitForCommand() after markCommandFinished() is called', function (): void {
        $stream = new RowStream();

        $stream->markCommandFinished();

        $result = await($stream->waitForCommand());

        expect($result)->toBeNull();
    });

    it('yields all pre-buffered rows in order during synchronous iteration', function (): void {
        $stream = new RowStream();
        $stream->push(['id' => 1]);
        $stream->push(['id' => 2]);
        $stream->push(['id' => 3]);
        $stream->complete(Mockery::mock(StreamStats::class));

        $rows = [];
        foreach ($stream as $row) {
            $rows[] = $row;
        }

        expect($rows)->toBe([['id' => 1], ['id' => 2], ['id' => 3]]);
    });
});