<?php

declare(strict_types=1);

use Hibla\MySQL\Manager\PoolManager;
use Tests\Helpers\TestHelper;

describe('PoolManager Statistics', function () {
    it('tracks active connections correctly', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);

        expect($pool->getStats()['active_connections'])->toBe(0);

        $connections = [];
        for ($i = 0; $i < 3; $i++) {
            $connections[] = $pool->get()->await();
        }

        expect($pool->getStats()['active_connections'])->toBe(3);

        foreach ($connections as $conn) {
            $pool->release($conn);
        }
        $pool->close();
    });

    it('tracks pooled connections correctly', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);
        $connections = [];

        for ($i = 0; $i < 3; $i++) {
            $connections[] = $pool->get()->await();
        }

        expect($pool->getStats()['pooled_connections'])->toBe(0);

        foreach ($connections as $conn) {
            $pool->release($conn);
        }

        expect($pool->getStats()['pooled_connections'])->toBe(3);

        $pool->close();
    });

    it('tracks waiting requests correctly', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 2);
        $connections = [];

        for ($i = 0; $i < 2; $i++) {
            $connections[] = $pool->get()->await();
        }

        expect($pool->getStats()['waiting_requests'])->toBe(0);

        $promises = [];
        for ($i = 0; $i < 3; $i++) {
            $promises[] = $pool->get();
        }

        expect($pool->getStats()['waiting_requests'])->toBe(3);

        foreach ($connections as $conn) {
            $pool->release($conn);
        }

        foreach ($promises as $promise) {
            try {
                $conn = $promise->await();
                $pool->release($conn);
            } catch (RuntimeException $e) {
                // Expected if pool closes before fulfillment
            }
        }

        $pool->close();
    });

    it('returns correct stats structure', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);
        $stats = $pool->getStats();

        expect($stats)->toHaveKeys([
            'active_connections',
            'pooled_connections',
            'waiting_requests',
            'max_size',
            'config_validated',
        ]);

        $pool->close();
    });
});
