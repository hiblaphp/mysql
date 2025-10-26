<?php

declare(strict_types=1);

use Hibla\MySQL\AsyncMySQLConnection;
use Tests\Helpers\TestHelper;

describe('AsyncMySQLConnection Execute', function () {
    it('executes INSERT and returns affected rows', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS orders')->await();
        $db->execute('
            CREATE TABLE orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_name VARCHAR(100) NOT NULL,
                total DECIMAL(10, 2) NOT NULL,
                status VARCHAR(20) DEFAULT "pending"
            )
        ')->await();

        $affected = $db->execute(
            "INSERT INTO orders (customer_name, total) VALUES ('John Doe', 299.99)"
        )->await();

        expect($affected)->toBe(1);

        $db->execute('DROP TABLE IF EXISTS orders')->await();
    });

    it('executes INSERT with parameters', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS orders')->await();
        $db->execute('
            CREATE TABLE orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_name VARCHAR(100) NOT NULL,
                total DECIMAL(10, 2) NOT NULL,
                status VARCHAR(20) DEFAULT "pending"
            )
        ')->await();

        $affected = $db->execute(
            'INSERT INTO orders (customer_name, total, status) VALUES (?, ?, ?)',
            ['Jane Smith', 599.99, 'completed']
        )->await();

        expect($affected)->toBe(1);

        $result = $db->fetchOne('SELECT * FROM orders WHERE customer_name = ?', ['Jane Smith'])->await();
        expect($result['total'])->toBe('599.99')
            ->and($result['status'])->toBe('completed')
        ;

        $db->execute('DROP TABLE IF EXISTS orders')->await();
    });

    it('executes multiple INSERTs', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS orders')->await();
        $db->execute('
            CREATE TABLE orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_name VARCHAR(100) NOT NULL,
                total DECIMAL(10, 2) NOT NULL,
                status VARCHAR(20) DEFAULT "pending"
            )
        ')->await();

        $affected1 = $db->execute(
            "INSERT INTO orders (customer_name, total) VALUES ('Alice', 100.00)"
        )->await();
        $affected2 = $db->execute(
            "INSERT INTO orders (customer_name, total) VALUES ('Bob', 200.00)"
        )->await();

        expect($affected1)->toBe(1)
            ->and($affected2)->toBe(1)
        ;

        $count = $db->fetchValue('SELECT COUNT(*) FROM orders')->await();
        expect($count)->toBe(2);

        $db->execute('DROP TABLE IF EXISTS orders')->await();
    });

    it('executes UPDATE and returns affected rows', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS orders')->await();
        $db->execute('
            CREATE TABLE orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_name VARCHAR(100) NOT NULL,
                total DECIMAL(10, 2) NOT NULL,
                status VARCHAR(20) DEFAULT "pending"
            )
        ')->await();

        $db->execute("INSERT INTO orders (customer_name, total) VALUES ('Charlie', 150.00)")->await();
        $db->execute("INSERT INTO orders (customer_name, total) VALUES ('David', 250.00)")->await();

        $affected = $db->execute(
            "UPDATE orders SET status = 'shipped' WHERE total > ?",
            [200]
        )->await();

        expect($affected)->toBe(1);

        $db->execute('DROP TABLE IF EXISTS orders')->await();
    });

    it('executes DELETE and returns affected rows', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS orders')->await();
        $db->execute('
            CREATE TABLE orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_name VARCHAR(100) NOT NULL,
                total DECIMAL(10, 2) NOT NULL,
                status VARCHAR(20) DEFAULT "pending"
            )
        ')->await();

        $db->execute("INSERT INTO orders (customer_name, total) VALUES ('Eve', 300.00)")->await();
        $db->execute("INSERT INTO orders (customer_name, total) VALUES ('Frank', 400.00)")->await();
        $db->execute("INSERT INTO orders (customer_name, total) VALUES ('Grace', 500.00)")->await();

        $affected = $db->execute('DELETE FROM orders WHERE total < ?', [350])->await();

        expect($affected)->toBe(1);

        $remaining = $db->fetchValue('SELECT COUNT(*) FROM orders')->await();
        expect($remaining)->toBe(2);

        $db->execute('DROP TABLE IF EXISTS orders')->await();
    });

    it('returns 0 for statements affecting no rows', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS orders')->await();
        $db->execute('
            CREATE TABLE orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_name VARCHAR(100) NOT NULL,
                total DECIMAL(10, 2) NOT NULL,
                status VARCHAR(20) DEFAULT "pending"
            )
        ')->await();

        $affected = $db->execute('UPDATE orders SET status = ? WHERE id = 999', ['cancelled'])->await();

        expect($affected)->toBe(0);

        $db->execute('DROP TABLE IF EXISTS orders')->await();
    });

    it('handles batch inserts', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS orders')->await();
        $db->execute('
            CREATE TABLE orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                customer_name VARCHAR(100) NOT NULL,
                total DECIMAL(10, 2) NOT NULL,
                status VARCHAR(20) DEFAULT "pending"
            )
        ')->await();

        $names = ['User1', 'User2', 'User3', 'User4', 'User5'];

        foreach ($names as $name) {
            $db->execute(
                'INSERT INTO orders (customer_name, total) VALUES (?, ?)',
                [$name, 100.00]
            )->await();
        }

        $count = $db->fetchValue('SELECT COUNT(*) FROM orders')->await();
        expect($count)->toBe(5);

        $db->execute('DROP TABLE IF EXISTS orders')->await();
    });
});