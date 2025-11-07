<?php

declare(strict_types=1);

use Hibla\MySQL\AsyncMySQLConnection;
use Tests\Helpers\TestHelper;

describe('AsyncMySQLConnection Query', function () {
    it('executes simple SELECT query', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS users')->await();
        $db->execute('
            CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                age INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ')->await();

        $db->execute("INSERT INTO users (name, email, age) VALUES ('John', 'john@example.com', 30)")->await();

        $result = $db->query('SELECT * FROM users')->await();

        expect($result)->toBeArray()
            ->and($result)->toHaveCount(1)
            ->and($result[0]['name'])->toBe('John')
            ->and($result[0]['email'])->toBe('john@example.com')
            ->and($result[0]['age'])->toBe(30)
        ;

        $db->execute('DROP TABLE IF EXISTS users')->await();
    });

    it('returns empty array for query with no results', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS users')->await();
        $db->execute('
            CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                age INT
            )
        ')->await();

        $result = $db->query('SELECT * FROM users WHERE id = 999')->await();

        expect($result)->toBeArray()
            ->and($result)->toHaveCount(0)
        ;

        $db->execute('DROP TABLE IF EXISTS users')->await();
    });

    it('executes parameterized query', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS users')->await();
        $db->execute('
            CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                age INT
            )
        ')->await();

        $db->execute("INSERT INTO users (name, email, age) VALUES ('Alice', 'alice@example.com', 25)")->await();
        $db->execute("INSERT INTO users (name, email, age) VALUES ('Bob', 'bob@example.com', 35)")->await();

        $result = $db->query('SELECT * FROM users WHERE age > ? ORDER BY age', [30])->await();

        expect($result)->toBeArray()
            ->and($result)->toHaveCount(1)
            ->and($result[0]['name'])->toBe('Bob')
        ;

        $db->execute('DROP TABLE IF EXISTS users')->await();
    });

    it('executes query with multiple parameters', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS users')->await();
        $db->execute('
            CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                age INT
            )
        ')->await();

        $db->execute("INSERT INTO users (name, email, age) VALUES ('Charlie', 'charlie@example.com', 28)")->await();
        $db->execute("INSERT INTO users (name, email, age) VALUES ('David', 'david@example.com', 32)")->await();

        $result = $db->query(
            'SELECT * FROM users WHERE age >= ? AND age <= ? ORDER BY age',
            [25, 30]
        )->await();

        expect($result)->toBeArray()
            ->and($result)->toHaveCount(1)
            ->and($result[0]['name'])->toBe('Charlie')
        ;

        $db->execute('DROP TABLE IF EXISTS users')->await();
    });

    it('handles NULL values correctly', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS users')->await();
        $db->execute('
            CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                age INT
            )
        ')->await();

        $db->execute("INSERT INTO users (name, email, age) VALUES ('Eve', 'eve@example.com', NULL)")->await();

        $result = $db->query('SELECT * FROM users WHERE name = ?', ['Eve'])->await();

        expect($result)->toBeArray()
            ->and($result)->toHaveCount(1)
            ->and($result[0]['age'])->toBeNull()
        ;

        $db->execute('DROP TABLE IF EXISTS users')->await();
    });

    it('throws exception for invalid SQL', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $exceptionThrown = false;

        try {
            $db->query('INVALID SQL STATEMENT')->await();
        } catch (Hibla\MySQL\Exceptions\QueryException $e) {
            $exceptionThrown = true;
        }

        expect($exceptionThrown)->toBeTrue();
    });

    it('executes multiple queries in sequence', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS users')->await();
        $db->execute('
            CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                age INT
            )
        ')->await();

        $db->execute("INSERT INTO users (name, email, age) VALUES ('Frank', 'frank@example.com', 40)")->await();

        $result1 = $db->query('SELECT COUNT(*) as count FROM users')->await();
        $result2 = $db->query('SELECT * FROM users WHERE name = ?', ['Frank'])->await();

        expect($result1[0]['count'])->toBe(1)
            ->and($result2[0]['name'])->toBe('Frank')
        ;

        $db->execute('DROP TABLE IF EXISTS users')->await();
    });
});
