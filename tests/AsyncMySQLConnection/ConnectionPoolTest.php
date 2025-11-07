<?php

declare(strict_types=1);

use Hibla\MySQL\AsyncMySQLConnection;
use Hibla\Promise\Promise;
use Tests\Helpers\TestHelper;

describe('AsyncMySQLConnection Connection Pool', function () {
    it('tracks connection pool statistics', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $stats = $db->getStats();

        expect($stats)->toHaveKeys([
            'active_connections',
            'pooled_connections',
            'waiting_requests',
            'max_size',
            'config_validated',
        ]);

        expect($stats['active_connections'])->toBeInt()
            ->and($stats['pooled_connections'])->toBeInt()
            ->and($stats['waiting_requests'])->toBeInt()
            ->and($stats['max_size'])->toBeInt()
            ->and($stats['max_size'])->toBe(5)
        ;
    });

    it('reuses connections from pool', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS test_pool')->await();
        $db->execute('
            CREATE TABLE test_pool (
                id INT AUTO_INCREMENT PRIMARY KEY,
                value TEXT NOT NULL
            )
        ')->await();

        $db->execute("INSERT INTO test_pool (value) VALUES ('test1')")->await();

        $statsAfterFirst = $db->getStats();

        $db->execute("INSERT INTO test_pool (value) VALUES ('test2')")->await();

        $statsAfterSecond = $db->getStats();

        expect($statsAfterFirst)->toBeArray()
            ->and($statsAfterSecond)->toBeArray()
            ->and($statsAfterFirst['active_connections'])->toBeGreaterThan(0)
        ;

        $db->execute('DROP TABLE IF EXISTS test_pool')->await();
    });

    it('handles pool exhaustion gracefully', function () {
        $poolSize = 2;
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), $poolSize);

        $db->execute('DROP TABLE IF EXISTS test_exhaustion')->await();
        $db->execute('
            CREATE TABLE test_exhaustion (
                id INT AUTO_INCREMENT PRIMARY KEY,
                value INT NOT NULL
            )
        ')->await();

        $promises = [];

        for ($i = 1; $i <= 5; $i++) {
            $promises[] = $db->execute(
                'INSERT INTO test_exhaustion (value) VALUES (?)',
                [$i]
            );
        }

        $results = Promise::all($promises)->await();

        expect($results)->toHaveCount(5);

        $count = $db->fetchValue('SELECT COUNT(*) FROM test_exhaustion')->await();
        expect((int)$count)->toBe(5);

        $db->execute('DROP TABLE IF EXISTS test_exhaustion')->await();
    });

    it('gets last connection used', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        expect($db->getLastConnection())->toBeNull();

        $db->execute('DROP TABLE IF EXISTS test_last')->await();
        $db->execute('
            CREATE TABLE test_last (
                id INT AUTO_INCREMENT PRIMARY KEY,
                value TEXT NOT NULL
            )
        ')->await();

        $db->execute("INSERT INTO test_last (value) VALUES ('test')")->await();

        $lastConn = $db->getLastConnection();
        expect($lastConn)->toBeInstanceOf(mysqli::class);

        $db->execute('DROP TABLE IF EXISTS test_last')->await();
    });

    it('handles run method with connection pool', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $result = $db->run(function ($conn) {
            expect($conn)->toBeInstanceOf(mysqli::class);

            return mysqli_get_server_info($conn);
        })->await();

        expect($result)->toBeString();
    });

    it('releases connection after run method', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 3);

        $promises = [];

        for ($i = 0; $i < 5; $i++) {
            $promises[] = $db->run(function ($conn) use ($i) {
                return "Result $i";
            });
        }

        $results = Promise::all($promises)->await();

        expect($results)->toHaveCount(5);

        $stats = $db->getStats();

        expect($stats['active_connections'])->toBeLessThanOrEqual(3);
        expect($stats['pooled_connections'])->toBeGreaterThanOrEqual(0);
    });

    it('handles errors in run method gracefully', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        expect(function () use ($db) {
            $db->run(function ($conn) {
                throw new RuntimeException('Test error in run');
            })->await();
        })->toThrow(RuntimeException::class);

        $result = $db->run(function ($conn) {
            return 'success';
        })->await();

        expect($result)->toBe('success');
    });
});
