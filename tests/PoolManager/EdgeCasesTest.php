<?php

declare(strict_types=1);

use Hibla\MySQL\Manager\PoolManager;
use Tests\Helpers\TestHelper;

describe('PoolManager Edge Cases', function () {
    it('handles max size of 1', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 1);

        $connection1 = $pool->get()->await();

        $promise = $pool->get();
        expect($pool->getStats()['waiting_requests'])->toBe(1);

        $pool->release($connection1);

        $connection2 = $promise->await();
        expect($connection2)->toBeInstanceOf(mysqli::class);
        expect($pool->getStats()['waiting_requests'])->toBe(0);

        $pool->release($connection2);
        $pool->close();
    });

    it('handles releasing same connection multiple times', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);

        $connection = $pool->get()->await();

        $pool->release($connection);
        expect($pool->getStats()['pooled_connections'])->toBe(1);

        $pool->release($connection);
        expect($pool->getStats()['pooled_connections'])->toBe(2);

        $pool->close();
    });

    it('works with actual database operations', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 5);

        $connection = $pool->get()->await();

        $connection->query('CREATE TEMPORARY TABLE test (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255))');
        $connection->query("INSERT INTO test (name) VALUES ('Alice')");

        $pool->release($connection);

        $connection2 = $pool->get()->await();

        $result = $connection2->query('SELECT * FROM test');
        $row = $result->fetch_assoc();
        expect($row['name'])->toBe('Alice');

        $pool->release($connection2);
        $pool->close();
    });

    it('maintains connection state across pool cycles', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 3);

        $conn1 = $pool->get()->await();
        $conn1->query('CREATE TEMPORARY TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255))');
        $conn1->query("INSERT INTO users (name) VALUES ('John')");

        $pool->release($conn1);

        $conn2 = $pool->get()->await();

        expect($conn2)->toBe($conn1);
        $result = $conn2->query('SELECT COUNT(*) as count FROM users');
        $row = $result->fetch_assoc();
        expect((int)$row['count'])->toBe(1);

        $pool->release($conn2);
        $pool->close();
    });

    it('handles concurrent requests properly', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 2);

        $conn1 = $pool->get()->await();
        $conn2 = $pool->get()->await();

        $promise1 = $pool->get();
        $promise2 = $pool->get();

        expect($pool->getStats()['waiting_requests'])->toBe(2);

        $pool->release($conn1);
        $pool->release($conn2);

        $conn3 = $promise1->await();
        $conn4 = $promise2->await();

        expect($conn3)->toBeInstanceOf(mysqli::class);
        expect($conn4)->toBeInstanceOf(mysqli::class);
        expect($pool->getStats()['waiting_requests'])->toBe(0);

        $pool->release($conn3);
        $pool->release($conn4);
        $pool->close();
    });

    it('handles connection with empty password', function () {
        $config = [
            'host' => 'localhost',
            'username' => 'root',
            'database' => 'test_db',
            'password' => '',
        ];

        $pool = new PoolManager($config, 5);

        expect($pool->getStats()['config_validated'])->toBeTrue();

        $pool->close();
    });

    it('handles connection without password field', function () {
        $config = [
            'host' => 'localhost',
            'username' => 'root',
            'database' => 'test_db',
        ];

        $pool = new PoolManager($config, 5);

        expect($pool->getStats()['config_validated'])->toBeTrue();

        $pool->close();
    });

    it('rejects waiting promise on connection creation failure', function () {
        $pool = new PoolManager(TestHelper::getTestConfig(), 1);

        $conn1 = $pool->get()->await();

        $badPool = new PoolManager([
            'host' => 'invalid-host-12345.example.com',
            'username' => 'root',
            'database' => 'test_db',
        ], 1);

        $promise = $badPool->get();

        try {
            $promise->await();
            expect(false)->toBeTrue('Should have thrown exception');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toContain('MySQL Connection failed');
        }

        $pool->release($conn1);
        $pool->close();
        $badPool->close();
    })->skip('Skipped to avoid DNS lookup warnings');

    it('handles charset configuration', function () {
        $config = array_merge(TestHelper::getTestConfig(), [
            'charset' => 'utf8mb4',
        ]);

        $pool = new PoolManager($config, 5);

        $connection = $pool->get()->await();

        $result = $connection->query("SHOW VARIABLES LIKE 'character_set_connection'");
        $row = $result->fetch_assoc();
        expect($row['Value'])->toBe('utf8mb4');

        $pool->release($connection);
        $pool->close();
    });
});
