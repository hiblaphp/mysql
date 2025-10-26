<?php

declare(strict_types=1);

use Hibla\MySQL\AsyncMySQLConnection;
use Hibla\Promise\Promise;
use Tests\Helpers\TestHelper;

describe('AsyncMySQLConnection Concurrent Operations', function () {
    it('executes multiple queries concurrently', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 10);

        $db->execute('DROP TABLE IF EXISTS logs')->await();
        $db->execute('
            CREATE TABLE logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message TEXT NOT NULL,
                level VARCHAR(20) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ')->await();

        $promises = [];

        for ($i = 1; $i <= 5; $i++) {
            $promises[] = $db->execute(
                'INSERT INTO logs (message, level) VALUES (?, ?)',
                ["Message $i", 'info']
            );
        }

        $results = Promise::all($promises)->await();

        expect($results)->toBeArray()
            ->and($results)->toHaveCount(5)
        ;

        foreach ($results as $affected) {
            expect($affected)->toBe(1);
        }

        $count = $db->fetchValue('SELECT COUNT(*) FROM logs')->await();
        expect($count)->toBe(5);

        $db->execute('DROP TABLE IF EXISTS logs')->await();
    });

    it('executes mixed query types concurrently', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 10);

        $db->execute('DROP TABLE IF EXISTS logs')->await();
        $db->execute('
            CREATE TABLE logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message TEXT NOT NULL,
                level VARCHAR(20) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ')->await();

        $db->execute("INSERT INTO logs (message, level) VALUES ('Initial', 'debug')")->await();

        $promises = [
            'insert' => $db->execute('INSERT INTO logs (message, level) VALUES (?, ?)', ['New Log', 'info']),
            'select' => $db->query('SELECT * FROM logs WHERE level = ?', ['debug']),
            'count' => $db->fetchValue('SELECT COUNT(*) FROM logs'),
        ];

        $results = Promise::all($promises)->await();

        expect($results['insert'])->toBe(1)
            ->and($results['select'])->toBeArray()
            ->and($results['count'])->toBeInt()
        ;

        $db->execute('DROP TABLE IF EXISTS logs')->await();
    });

    it('handles concurrent operations with connection pool limit', function () {
        $poolSize = 3;
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), $poolSize);

        $db->execute('DROP TABLE IF EXISTS test_concurrent')->await();
        $db->execute('
            CREATE TABLE test_concurrent (
                id INT AUTO_INCREMENT PRIMARY KEY,
                value INT NOT NULL
            )
        ')->await();

        $promises = [];
        $operationCount = 10; // More than pool size

        for ($i = 1; $i <= $operationCount; $i++) {
            $promises[] = $db->execute(
                'INSERT INTO test_concurrent (value) VALUES (?)',
                [$i]
            );
        }

        $results = Promise::all($promises)->await();

        expect($results)->toHaveCount($operationCount);

        $count = $db->fetchValue('SELECT COUNT(*) FROM test_concurrent')->await();
        expect($count)->toBe($operationCount);

        $db->execute('DROP TABLE test_concurrent')->await();
    });

    it('executes queries with Promise::concurrent', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 10);

        $db->execute('DROP TABLE IF EXISTS logs')->await();
        $db->execute('
            CREATE TABLE logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message TEXT NOT NULL,
                level VARCHAR(20) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ')->await();

        $tasks = [];

        for ($i = 1; $i <= 10; $i++) {
            $tasks[] = fn () => $db->execute(
                'INSERT INTO logs (message, level) VALUES (?, ?)',
                ["Concurrent Message $i", 'warning']
            );
        }

        $results = Promise::concurrent($tasks, 5)->await(); // 5 concurrent operations

        expect($results)->toHaveCount(10);

        $count = $db->fetchValue('SELECT COUNT(*) FROM logs')->await();
        expect($count)->toBe(10);

        $db->execute('DROP TABLE IF EXISTS logs')->await();
    });

    it('handles concurrent reads and writes', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 10);

        $db->execute('DROP TABLE IF EXISTS logs')->await();
        $db->execute('
            CREATE TABLE logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message TEXT NOT NULL,
                level VARCHAR(20) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ')->await();

        // Insert initial data
        for ($i = 1; $i <= 5; $i++) {
            $db->execute(
                'INSERT INTO logs (message, level) VALUES (?, ?)',
                ["Init $i", 'info']
            )->await();
        }

        $promises = [];

        // Concurrent reads
        for ($i = 0; $i < 5; $i++) {
            $promises[] = $db->query('SELECT * FROM logs WHERE level = ?', ['info']);
        }

        // Concurrent writes
        for ($i = 1; $i <= 5; $i++) {
            $promises[] = $db->execute(
                'INSERT INTO logs (message, level) VALUES (?, ?)',
                ["New $i", 'error']
            );
        }

        $results = Promise::all($promises)->await();

        expect($results)->toHaveCount(10);

        $totalCount = $db->fetchValue('SELECT COUNT(*) FROM logs')->await();
        expect($totalCount)->toBe(10);

        $db->execute('DROP TABLE IF EXISTS logs')->await();
    });

    it('maintains data consistency under concurrent load', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 10);

        $db->execute('DROP TABLE IF EXISTS logs')->await();
        $db->execute('
            CREATE TABLE logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message TEXT NOT NULL,
                level VARCHAR(20) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ')->await();

        $promises = [];
        $insertCount = 20;

        for ($i = 1; $i <= $insertCount; $i++) {
            $promises[] = $db->execute(
                'INSERT INTO logs (message, level) VALUES (?, ?)',
                ["Log Entry $i", $i % 2 === 0 ? 'info' : 'error']
            );
        }

        Promise::all($promises)->await();

        $totalCount = $db->fetchValue('SELECT COUNT(*) FROM logs')->await();
        $infoCount = $db->fetchValue("SELECT COUNT(*) FROM logs WHERE level = 'info'")->await();
        $errorCount = $db->fetchValue("SELECT COUNT(*) FROM logs WHERE level = 'error'")->await();

        expect($totalCount)->toBe($insertCount)
            ->and($infoCount + $errorCount)->toBe($insertCount)
        ;

        $db->execute('DROP TABLE IF EXISTS logs')->await();
    });

    it('handles concurrent operations with run method', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 10);

        $db->execute('DROP TABLE IF EXISTS logs')->await();
        $db->execute('
            CREATE TABLE logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message TEXT NOT NULL,
                level VARCHAR(20) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ')->await();

        $promises = [];

        for ($i = 1; $i <= 5; $i++) {
            $promises[] = $db->run(function ($conn) use ($i) {
                $stmt = mysqli_prepare(
                    $conn,
                    'INSERT INTO logs (message, level) VALUES (?, ?) '
                );
                
                $message = "Custom Insert $i";
                $level = 'debug';
                mysqli_stmt_bind_param($stmt, 'ss', $message, $level);
                mysqli_stmt_execute($stmt);

                return mysqli_insert_id($conn);
            });
        }

        $ids = Promise::all($promises)->await();

        expect($ids)->toHaveCount(5);

        foreach ($ids as $id) {
            expect($id)->toBeInt();
        }

        $db->execute('DROP TABLE IF EXISTS logs')->await();
    });
});