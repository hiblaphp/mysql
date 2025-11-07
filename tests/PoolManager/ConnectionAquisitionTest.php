<?php

declare(strict_types=1);

use Hibla\MySQL\Manager\PoolManager;
use Hibla\Promise\Interfaces\PromiseInterface;
use Tests\Helpers\TestHelper;

describe('PoolManager Connection Acquisition', function () {
    it('gets a connection from empty pool', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);
        $promise = $pool->get();

        expect($promise)->toBeInstanceOf(PromiseInterface::class);

        $connection = $promise->await();
        expect($connection)->toBeInstanceOf(mysqli::class);

        $stats = $pool->getStats();
        expect($stats['active_connections'])->toBe(1);

        $pool->release($connection);
        $pool->close();
    });

    it('creates new connection when pool is empty', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);

        $connection = $pool->get()->await();

        expect($connection)->toBeInstanceOf(mysqli::class);

        $stats = $pool->getStats();
        expect($stats['active_connections'])->toBe(1)
            ->and($stats['pooled_connections'])->toBe(0)
        ;

        $pool->release($connection);
        $pool->close();
    });

    it('reuses connection from pool', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);

        $connection1 = $pool->get()->await();
        $pool->release($connection1);

        $stats = $pool->getStats();
        expect($stats['pooled_connections'])->toBe(1);

        $connection2 = $pool->get()->await();

        expect($connection2)->toBe($connection1); // Same instance

        $stats = $pool->getStats();
        expect($stats['pooled_connections'])->toBe(0)
            ->and($stats['active_connections'])->toBe(1)
        ;

        $pool->release($connection2);
        $pool->close();
    });

    it('creates multiple connections up to max size', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 3);
        $connections = [];

        for ($i = 0; $i < 3; $i++) {
            $connections[] = $pool->get()->await();
        }

        expect($connections)->toHaveCount(3);

        foreach ($connections as $conn) {
            expect($conn)->toBeInstanceOf(mysqli::class);
        }

        $stats = $pool->getStats();
        expect($stats['active_connections'])->toBe(3);

        foreach ($connections as $conn) {
            $pool->release($conn);
        }
        $pool->close();
    });

    it('queues requests when pool is full', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 2);
        $connections = [];

        for ($i = 0; $i < 2; $i++) {
            $connections[] = $pool->get()->await();
        }

        $waitingPromise = $pool->get();

        $stats = $pool->getStats();
        expect($stats['waiting_requests'])->toBe(1);
        expect($waitingPromise->isPending())->toBeTrue();

        $pool->release($connections[0]);

        $waitingConnection = $waitingPromise->await();
        expect($waitingConnection)->toBeInstanceOf(mysqli::class);

        $stats = $pool->getStats();
        expect($stats['waiting_requests'])->toBe(0);

        $pool->release($waitingConnection);
        $pool->release($connections[1]);
        $pool->close();
    });

    it('handles multiple queued requests', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 1);

        $connection1 = $pool->get()->await();

        $promises = [];
        for ($i = 0; $i < 3; $i++) {
            $promises[] = $pool->get();
        }

        $stats = $pool->getStats();
        expect($stats['waiting_requests'])->toBe(3);

        $pool->release($connection1);

        $connection2 = $promises[0]->await();
        expect($connection2)->toBeInstanceOf(mysqli::class);

        $stats = $pool->getStats();
        expect($stats['waiting_requests'])->toBe(2);

        $pool->release($connection2);

        // Handle the remaining waiting promises before closing
        foreach (array_slice($promises, 1) as $promise) {
            try {
                $conn = $promise->await();
                $pool->release($conn);
            } catch (RuntimeException $e) {
                // Expected when pool closes
            }
        }

        $pool->close();
    });

    it('tracks last connection', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);

        expect($pool->getLastConnection())->toBeNull();

        $connection = $pool->get()->await();

        expect($pool->getLastConnection())->toBe($connection);

        $pool->release($connection);
        $pool->close();
    });

    it('returns rejected promise on connection failure', function () {
        $pool = new PoolManager([
            'host' => 'invalid-host-that-does-not-exist',
            'username' => 'root',
            'database' => 'test_db',
        ], 5);

        $promise = $pool->get();

        expect(function () use ($promise) {
            $promise->await();
        })->toThrow(RuntimeException::class);

        $pool->close();
    })->skip('Skipping test to avoid DNS lookup warnings');
});
