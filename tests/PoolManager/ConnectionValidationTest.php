<?php

declare(strict_types=1);

use Hibla\MySQL\Manager\PoolManager;
use Tests\Helpers\TestHelper;

describe('PoolManager Connection Validation', function () {
    it('validates connection is alive with query', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);

        $connection = $pool->get()->await();

        $result = $connection->query('SELECT 1');
        expect($result)->toBeInstanceOf(mysqli_result::class);

        $pool->release($connection);

        $stats = $pool->getStats();
        expect($stats['pooled_connections'])->toBe(1);

        $pool->close();
    });

    it('detects dead connection', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);

        $connection = $pool->get()->await();

        $result = $connection->query('SELECT 1');
        expect($result)->toBeInstanceOf(mysqli_result::class);

        $connection->close();

        $initialActive = $pool->getStats()['active_connections'];
        $pool->release($connection);

        expect($pool->getStats()['active_connections'])->toBeLessThan($initialActive);

        $pool->close();
    });

    it('validates connection thread ID', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);

        $connection = $pool->get()->await();

        expect($connection->thread_id)->toBeGreaterThan(0);

        $pool->release($connection);
        $pool->close();
    });
});