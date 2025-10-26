<?php

declare(strict_types=1);

use Hibla\MySQL\AsyncMySQLConnection;
use Hibla\MySQL\Exceptions\TransactionFailedException;
use Hibla\Promise\Promise;
use Tests\Helpers\TestHelper;

describe('AsyncMySQLConnection Transactions', function () {
    it('commits successful transaction', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
        $db->execute('
            CREATE TABLE accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                balance DECIMAL(10, 2) NOT NULL DEFAULT 0
            )
        ')->await();

        $result = $db->transaction(function ($conn) {
            mysqli_query($conn, "INSERT INTO accounts (name, balance) VALUES ('Alice', 1000.00)");
            mysqli_query($conn, "INSERT INTO accounts (name, balance) VALUES ('Bob', 2000.00)");

            return 'success';
        })->await();

        expect($result)->toBe('success');

        $count = $db->fetchValue('SELECT COUNT(*) FROM accounts')->await();
        expect($count)->toBe(2);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
    });

    it('rolls back transaction on exception', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
        $db->execute('
            CREATE TABLE accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                balance DECIMAL(10, 2) NOT NULL DEFAULT 0
            )
        ')->await();

        try {
            $db->transaction(function ($conn) {
                mysqli_query($conn, "INSERT INTO accounts (name, balance) VALUES ('Charlie', 500.00)");

                throw new Exception('Simulated error');
            })->await();
        } catch (TransactionFailedException $e) {
            // Expected
        }

        $count = $db->fetchValue('SELECT COUNT(*) FROM accounts')->await();
        expect($count)->toBe(0);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
    });

    it('performs money transfer with transaction', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
        $db->execute('
            CREATE TABLE accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                balance DECIMAL(10, 2) NOT NULL DEFAULT 0
            )
        ')->await();

        $db->execute("INSERT INTO accounts (name, balance) VALUES ('Alice', 1000.00)")->await();
        $db->execute("INSERT INTO accounts (name, balance) VALUES ('Bob', 500.00)")->await();

        $db->transaction(function ($conn) {
            $transferAmount = 300.00;

            $stmt = mysqli_prepare($conn, 'UPDATE accounts SET balance = balance - ? WHERE name = ?');
            mysqli_stmt_bind_param($stmt, 'ds', $transferAmount, $name);
            $name = 'Alice';
            mysqli_stmt_execute($stmt);

            $stmt = mysqli_prepare($conn, 'UPDATE accounts SET balance = balance + ? WHERE name = ?');
            mysqli_stmt_bind_param($stmt, 'ds', $transferAmount, $name);
            $name = 'Bob';
            mysqli_stmt_execute($stmt);
        })->await();

        $aliceBalance = $db->fetchValue("SELECT balance FROM accounts WHERE name = 'Alice'")->await();
        $bobBalance = $db->fetchValue("SELECT balance FROM accounts WHERE name = 'Bob'")->await();

        expect($aliceBalance)->toBe('700.00')
            ->and($bobBalance)->toBe('800.00')
        ;

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
    });

    it('retries transaction on failure', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
        $db->execute('
            CREATE TABLE accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                balance DECIMAL(10, 2) NOT NULL DEFAULT 0
            )
        ')->await();

        $attempts = 0;

        try {
            $db->transaction(function ($conn) use (&$attempts) {
                $attempts++;

                mysqli_query($conn, "INSERT INTO accounts (name, balance) VALUES ('David', 100.00)");

                if ($attempts < 3) {
                    throw new Exception('Retry me');
                }

                return 'completed';
            }, 3)->await();
        } catch (TransactionFailedException $e) {
            // Should not reach here
        }

        expect($attempts)->toBe(3);

        $count = $db->fetchValue('SELECT COUNT(*) FROM accounts')->await();
        expect($count)->toBe(1);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
    });

    it('fails after max retry attempts', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
        $db->execute('
            CREATE TABLE accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                balance DECIMAL(10, 2) NOT NULL DEFAULT 0
            )
        ')->await();

        $attempts = 0;

        expect(function () use ($db, &$attempts) {
            $db->transaction(function ($conn) use (&$attempts) {
                $attempts++;

                throw new Exception('Always fail');
            }, 2)->await();
        })->toThrow(TransactionFailedException::class);

        expect($attempts)->toBe(2);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
    });

    it('returns value from transaction', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
        $db->execute('
            CREATE TABLE accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                balance DECIMAL(10, 2) NOT NULL DEFAULT 0
            )
        ')->await();

        $insertedId = $db->transaction(function ($conn) {
            $result = mysqli_query($conn, "INSERT INTO accounts (name, balance) VALUES ('Eve', 750.00)");
            return mysqli_insert_id($conn);
        })->await();

        expect($insertedId)->toBeInt();

        $account = $db->fetchOne('SELECT * FROM accounts WHERE id = ?', [$insertedId])->await();
        expect($account['name'])->toBe('Eve');

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
    });

    it('handles nested queries within transaction', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
        $db->execute('
            CREATE TABLE accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                balance DECIMAL(10, 2) NOT NULL DEFAULT 0
            )
        ')->await();

        $db->transaction(function ($conn) {
            mysqli_query($conn, "INSERT INTO accounts (name, balance) VALUES ('Frank', 1500.00)");

            $result = mysqli_query($conn, "SELECT * FROM accounts WHERE name = 'Frank'");
            $account = mysqli_fetch_assoc($result);

            expect($account['balance'])->toBe('1500.00');

            $stmt = mysqli_prepare($conn, 'UPDATE accounts SET balance = ? WHERE name = ?');
            $balance = 2000.00;
            $name = 'Frank';
            mysqli_stmt_bind_param($stmt, 'ds', $balance, $name);
            mysqli_stmt_execute($stmt);
        })->await();

        $balance = $db->fetchValue("SELECT balance FROM accounts WHERE name = 'Frank'")->await();
        expect($balance)->toBe('2000.00');

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
    });

    it('isolates transactions across concurrent operations', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
        $db->execute('
        CREATE TABLE accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            balance DECIMAL(10, 2) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB
    ')->await();

        $db->execute("INSERT INTO accounts (name, balance) VALUES ('Grace', 1000.00)")->await();

        $promise1 = $db->transaction(function ($conn) {
            $result = mysqli_query($conn, "SELECT balance FROM accounts WHERE name = 'Grace' FOR UPDATE");
            $row = mysqli_fetch_assoc($result);
            $currentBalance = (float)$row['balance'];

            $stmt = mysqli_prepare($conn, 'UPDATE accounts SET balance = ? WHERE name = ?');
            $newBalance = $currentBalance + 100;
            $name = 'Grace';
            mysqli_stmt_bind_param($stmt, 'ds', $newBalance, $name);
            mysqli_stmt_execute($stmt);

            return $currentBalance + 100;
        });

        $promise2 = $db->transaction(function ($conn) {
            $result = mysqli_query($conn, "SELECT balance FROM accounts WHERE name = 'Grace' FOR UPDATE");
            $row = mysqli_fetch_assoc($result);
            $currentBalance = (float)$row['balance'];

            $stmt = mysqli_prepare($conn, 'UPDATE accounts SET balance = ? WHERE name = ?');
            $newBalance = $currentBalance + 200;
            $name = 'Grace';
            mysqli_stmt_bind_param($stmt, 'ds', $newBalance, $name);
            mysqli_stmt_execute($stmt);

            return $currentBalance + 200;
        });

        Promise::all([$promise1, $promise2])->await();

        $finalBalance = $db->fetchValue("SELECT balance FROM accounts WHERE name = 'Grace'")->await();
        expect((float)$finalBalance)->toBe(1300.00);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
    });
});
