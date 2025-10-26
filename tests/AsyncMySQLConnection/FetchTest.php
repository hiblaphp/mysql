<?php

declare(strict_types=1);

use Hibla\MySQL\AsyncMySQLConnection;
use Tests\Helpers\TestHelper;

describe('AsyncMySQLConnection Fetch Methods', function () {
    describe('fetchOne', function () {
        it('fetches single row', function () {
            $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

            $db->execute('DROP TABLE IF EXISTS products')->await();
            $db->execute('
                CREATE TABLE products (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    price DECIMAL(10, 2) NOT NULL,
                    stock INT DEFAULT 0
                )
            ')->await();

            $db->execute("INSERT INTO products (name, price, stock) VALUES ('Laptop', 999.99, 10)")->await();

            $result = $db->fetchOne('SELECT * FROM products WHERE name = ?', ['Laptop'])->await();

            expect($result)->toBeArray()
                ->and($result['name'])->toBe('Laptop')
                ->and($result['price'])->toBe('999.99')
                ->and($result['stock'])->toBe(10)
            ;

            $db->execute('DROP TABLE IF EXISTS products')->await();
        });

        it('returns null when no rows match', function () {
            $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

            $db->execute('DROP TABLE IF EXISTS products')->await();
            $db->execute('
                CREATE TABLE products (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    price DECIMAL(10, 2) NOT NULL,
                    stock INT DEFAULT 0
                )
            ')->await();

            $result = $db->fetchOne('SELECT * FROM products WHERE id = 999')->await();

            expect($result)->toBeNull();

            $db->execute('DROP TABLE IF EXISTS products')->await();
        });

        it('returns first row when multiple match', function () {
            $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

            $db->execute('DROP TABLE IF EXISTS products')->await();
            $db->execute('
                CREATE TABLE products (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    price DECIMAL(10, 2) NOT NULL,
                    stock INT DEFAULT 0
                )
            ')->await();

            $db->execute("INSERT INTO products (name, price, stock) VALUES ('Mouse', 29.99, 50)")->await();
            $db->execute("INSERT INTO products (name, price, stock) VALUES ('Keyboard', 79.99, 30)")->await();

            $result = $db->fetchOne('SELECT * FROM products ORDER BY price')->await();

            expect($result)->toBeArray()
                ->and($result['name'])->toBe('Mouse')
            ;

            $db->execute('DROP TABLE IF EXISTS products')->await();
        });
    });

    describe('fetchValue', function () {
        it('fetches single column value', function () {
            $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

            $db->execute('DROP TABLE IF EXISTS products')->await();
            $db->execute('
                CREATE TABLE products (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    price DECIMAL(10, 2) NOT NULL,
                    stock INT DEFAULT 0
                )
            ')->await();

            $db->execute("INSERT INTO products (name, price, stock) VALUES ('Monitor', 299.99, 15)")->await();

            $result = $db->fetchValue('SELECT COUNT(*) FROM products')->await();

            expect($result)->toBe(1);

            $db->execute('DROP TABLE IF EXISTS products')->await();
        });

        it('fetches value from specific column', function () {
            $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

            $db->execute('DROP TABLE IF EXISTS products')->await();
            $db->execute('
                CREATE TABLE products (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    price DECIMAL(10, 2) NOT NULL,
                    stock INT DEFAULT 0
                )
            ')->await();

            $db->execute("INSERT INTO products (name, price, stock) VALUES ('Headphones', 149.99, 25)")->await();

            $result = $db->fetchValue('SELECT price FROM products WHERE name = ?', ['Headphones'])->await();

            expect($result)->toBe('149.99');

            $db->execute('DROP TABLE IF EXISTS products')->await();
        });

        it('returns null when no rows match', function () {
            $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

            $db->execute('DROP TABLE IF EXISTS products')->await();
            $db->execute('
                CREATE TABLE products (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    price DECIMAL(10, 2) NOT NULL,
                    stock INT DEFAULT 0
                )
            ')->await();

            $result = $db->fetchValue('SELECT price FROM products WHERE id = 999')->await();

            expect($result)->toBeNull();

            $db->execute('DROP TABLE IF EXISTS products')->await();
        });

        it('fetches aggregate functions', function () {
            $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

            $db->execute('DROP TABLE IF EXISTS products')->await();
            $db->execute('
                CREATE TABLE products (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL,
                    price DECIMAL(10, 2) NOT NULL,
                    stock INT DEFAULT 0
                )
            ')->await();

            $db->execute("INSERT INTO products (name, price, stock) VALUES ('Item1', 100.00, 10)")->await();
            $db->execute("INSERT INTO products (name, price, stock) VALUES ('Item2', 200.00, 20)")->await();
            $db->execute("INSERT INTO products (name, price, stock) VALUES ('Item3', 300.00, 30)")->await();

            $count = $db->fetchValue('SELECT COUNT(*) FROM products')->await();
            $maxPrice = $db->fetchValue('SELECT MAX(price) FROM products')->await();
            $totalStock = $db->fetchValue('SELECT SUM(stock) FROM products')->await();

            expect($count)->toBe(3)
                ->and($maxPrice)->toBe('300.00')
                ->and((int)$totalStock)->toBe(60)
            ;

            $db->execute('DROP TABLE IF EXISTS products')->await();
        });
    });
});
