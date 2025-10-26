<?php

declare(strict_types=1);

use Hibla\MySQL\Manager\PoolManager;
use Tests\Helpers\TestHelper;

describe('PoolManager Constructor', function () {
    it('creates a pool with valid configuration', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);
        $stats = $pool->getStats();

        expect($stats['max_size'])->toBe(5)
            ->and($stats['active_connections'])->toBe(0)
            ->and($stats['pooled_connections'])->toBe(0)
            ->and($stats['waiting_requests'])->toBe(0)
            ->and($stats['config_validated'])->toBeTrue()
        ;

        $pool->close();
    });

    it('uses default max size of 10', function () {
        $pool = new PoolManager(TestHelper::getTestConfig());
        $stats = $pool->getStats();

        expect($stats['max_size'])->toBe(10);

        $pool->close();
    });

    it('throws exception for empty configuration', function () {
        expect(fn() => new PoolManager([]))
            ->toThrow(InvalidArgumentException::class, 'Database configuration cannot be empty');
    });

    it('throws exception for missing host', function () {
        expect(fn() => new PoolManager([
            'username' => 'root',
            'database' => 'test_db',
        ]))->toThrow(InvalidArgumentException::class, "Missing required database configuration field: 'host'");
    });

    it('throws exception for missing username', function () {
        expect(fn() => new PoolManager([
            'host' => 'localhost',
            'database' => 'test_db',
        ]))->toThrow(InvalidArgumentException::class, "Missing required database configuration field: 'username'");
    });

    it('throws exception for missing database', function () {
        expect(fn() => new PoolManager([
            'host' => 'localhost',
            'username' => 'root',
        ]))->toThrow(InvalidArgumentException::class, "Missing required database configuration field: 'database'");
    });

    it('throws exception for empty host', function () {
        expect(fn() => new PoolManager([
            'host' => '',
            'username' => 'root',
            'database' => 'test_db',
        ]))->toThrow(InvalidArgumentException::class, "Database configuration field 'host' cannot be empty");
    });

    it('throws exception for empty database', function () {
        expect(fn() => new PoolManager([
            'host' => 'localhost',
            'username' => 'root',
            'database' => '',
        ]))->toThrow(InvalidArgumentException::class, "Database configuration field 'database' cannot be empty");
    });

    it('throws exception for null host', function () {
        expect(fn() => new PoolManager([
            'host' => null,
            'username' => 'root',
            'database' => 'test_db',
        ]))->toThrow(InvalidArgumentException::class, "Database configuration field 'host' cannot be empty");
    });

    it('throws exception for invalid port type', function () {
        expect(fn() => new PoolManager([
            'host' => 'localhost',
            'username' => 'root',
            'database' => 'test_db',
            'port' => 'invalid',
        ]))->toThrow(InvalidArgumentException::class, 'Database port must be an integer'); 
    });

    it('throws exception for negative port', function () {
        expect(fn() => new PoolManager([
            'host' => 'localhost',
            'username' => 'root',
            'database' => 'test_db',
            'port' => -1,
        ]))->toThrow(InvalidArgumentException::class, 'Database port must be a positive integer');
    });

    it('throws exception for zero port', function () {
        expect(fn() => new PoolManager([
            'host' => 'localhost',
            'username' => 'root',
            'database' => 'test_db',
            'port' => 0,
        ]))->toThrow(InvalidArgumentException::class, 'Database port must be a positive integer');
    });

    it('throws exception for non-string host', function () {
        expect(fn() => new PoolManager([
            'host' => 12345,
            'username' => 'root',
            'database' => 'test_db',
        ]))->toThrow(InvalidArgumentException::class, 'Database host must be a string');
    });

    it('throws exception for pool size less than 1', function () {
        expect(fn() => new PoolManager(TestHelper::getTestConfig(), 0))
            ->toThrow(InvalidArgumentException::class, 'Pool size must be at least 1');
    });

    it('throws exception for negative pool size', function () {
        expect(fn() => new PoolManager(TestHelper::getTestConfig(), -5))
            ->toThrow(InvalidArgumentException::class, 'Pool size must be at least 1');
    });

    it('accepts valid socket configuration', function () {
        $pool = new PoolManager([
            'host' => 'localhost',
            'username' => 'root',
            'database' => 'test_db',
            'socket' => '/var/run/mysqld/mysqld.sock',
        ], 5);

        expect($pool->getStats()['config_validated'])->toBeTrue();

        $pool->close();
    });
});
