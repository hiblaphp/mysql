<?php

declare(strict_types=1);

namespace Hibla\Mysql\Tests\ValueObjects;

use Hibla\Mysql\ValueObjects\StreamStats;

describe('StreamStats', function (): void {

    it('initializes correctly with provided values', function (): void {
        $stats = new StreamStats(
            rowCount: 1000,
            columnCount: 5,
            duration: 2.5,
            warningCount: 1,
            connectionId: 123
        );

        expect($stats->rowCount)->toBe(1000)
            ->and($stats->columnCount)->toBe(5)
            ->and($stats->duration)->toBe(2.5)
            ->and($stats->warningCount)->toBe(1)
            ->and($stats->connectionId)->toBe(123);
    });

    it('calculates rowsPerSecond correctly', function (): void {
        $stats = new StreamStats(rowCount: 1000, columnCount: 1, duration: 2.0);

        expect($stats->rowsPerSecond)->toBe(500.0);
    });

    it('returns 0.0 for rowsPerSecond if duration is zero to avoid division by zero', function (): void {
        $stats = new StreamStats(rowCount: 1000, columnCount: 1, duration: 0.0);

        expect($stats->rowsPerSecond)->toBe(0.0);
    });

    it('correctly reports hasRows', function (): void {
        $empty = new StreamStats(rowCount: 0, columnCount: 5, duration: 1.0);
        expect($empty->hasRows())->toBeFalse();

        $withData = new StreamStats(rowCount: 1, columnCount: 5, duration: 1.0);
        expect($withData->hasRows())->toBeTrue();
    });

    it('defaults warningCount and connectionId to zero', function (): void {
        $stats = new StreamStats(100, 2, 0.5);

        expect($stats->warningCount)->toBe(0)
            ->and($stats->connectionId)->toBe(0);
    });
});