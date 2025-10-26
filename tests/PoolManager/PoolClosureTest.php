<?php

declare(strict_types=1);

use Hibla\MySQL\Manager\PoolManager;
use Hibla\MySQL\Exceptions\PoolException;
use Tests\Helpers\TestHelper;

describe('PoolManager Closure', function () {
    it('clears all pooled connections', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);
        $connections = [];

        for ($i = 0; $i < 3; $i++) {
            $connections[] = $pool->get()->await();
        }

        foreach ($connections as $conn) {
            $pool->release($conn);
        }

        expect($pool->getStats()['pooled_connections'])->toBe(3);

        $pool->close();

        $stats = $pool->getStats();
        expect($stats['pooled_connections'])->toBe(0)
            ->and($stats['active_connections'])->toBe(0)
        ;
    });

    it('rejects all waiting requests', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 1);

        $connection = $pool->get()->await();

        $promises = [];
        for ($i = 0; $i < 3; $i++) {
            $promises[] = $pool->get();
        }

        expect($pool->getStats()['waiting_requests'])->toBe(3);

        $pool->close();

        foreach ($promises as $promise) {
            try {
                $promise->await();
                expect(false)->toBeTrue('Should have thrown an exception');
            } catch (PoolException $e) {
                expect($e->getMessage())->toBe('Pool is being closed');
            }
        }

        $connection->close();
    });

    it('clears last connection', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);

        $connection = $pool->get()->await();
        expect($pool->getLastConnection())->toBeInstanceOf(mysqli::class);

        $pool->release($connection);
        $pool->close();

        expect($pool->getLastConnection())->toBeNull();
    });

    it('resets all counters', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);
        $connections = [];

        for ($i = 0; $i < 3; $i++) {
            $connections[] = $pool->get()->await();
        }

        foreach ($connections as $conn) {
            $pool->release($conn);
        }

        $pool->close();

        $stats = $pool->getStats();
        expect($stats['active_connections'])->toBe(0)
            ->and($stats['pooled_connections'])->toBe(0)
            ->and($stats['waiting_requests'])->toBe(0)
        ;
    });

    it('closes live connections properly', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 3);
        $connections = [];

        for ($i = 0; $i < 3; $i++) {
            $connections[] = $pool->get()->await();
        }

        foreach ($connections as $conn) {
            expect($conn->ping())->toBeTrue();
        }

        foreach ($connections as $conn) {
            $pool->release($conn);
        }

        $pool->close();

        expect($pool->getStats()['pooled_connections'])->toBe(0);
    });
});