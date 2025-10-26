<?php

declare(strict_types=1);

use Hibla\MySQL\AsyncMySQLConnection;
use Hibla\MySQL\Exceptions\NotInTransactionException;
use Tests\Helpers\TestHelper;

describe('AsyncMySQLConnection Transaction Callbacks', function () {
    it('executes onCommit callback after successful transaction', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS callback_test')->await();
        $db->execute('
            CREATE TABLE callback_test (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            )
        ')->await();

        $commitCalled = false;

        $db->transaction(function ($conn) use ($db, &$commitCalled) {
            mysqli_query($conn, "INSERT INTO callback_test (name) VALUES ('Alice')");

            $db->onCommit(function () use (&$commitCalled) {
                $commitCalled = true;
            });
        })->await();

        expect($commitCalled)->toBeTrue();

        $count = $db->fetchValue('SELECT COUNT(*) FROM callback_test')->await();
        expect($count)->toBe(1);

        $db->execute('DROP TABLE IF EXISTS callback_test')->await();
    });

    it('executes onRollback callback after failed transaction', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS callback_test')->await();
        $db->execute('
            CREATE TABLE callback_test (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            )
        ')->await();

        $rollbackCalled = false;

        try {
            $db->transaction(function ($conn) use ($db, &$rollbackCalled) {
                mysqli_query($conn, "INSERT INTO callback_test (name) VALUES ('Bob')");

                $db->onRollback(function () use (&$rollbackCalled) {
                    $rollbackCalled = true;
                });

                throw new Exception('Force rollback');
            })->await();
        } catch (Hibla\MySQL\Exceptions\TransactionFailedException $e) {
            // Expected
        }

        expect($rollbackCalled)->toBeTrue();

        $count = $db->fetchValue('SELECT COUNT(*) FROM callback_test')->await();
        expect($count)->toBe(0);

        $db->execute('DROP TABLE IF EXISTS callback_test')->await();
    });

    it('throws exception when onCommit called outside transaction', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        expect(function () use ($db) {
            $db->onCommit(function () {
                // This should not execute
            });
        })->toThrow(NotInTransactionException::class);
    });

    it('throws exception when onRollback called outside transaction', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        expect(function () use ($db) {
            $db->onRollback(function () {
                // This should not execute
            });
        })->toThrow(NotInTransactionException::class);
    });

    it('executes multiple onCommit callbacks in order', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS callback_test')->await();
        $db->execute('
            CREATE TABLE callback_test (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            )
        ')->await();

        $callOrder = [];

        $db->transaction(function ($conn) use ($db, &$callOrder) {
            mysqli_query($conn, "INSERT INTO callback_test (name) VALUES ('Charlie')");

            $db->onCommit(function () use (&$callOrder) {
                $callOrder[] = 1;
            });

            $db->onCommit(function () use (&$callOrder) {
                $callOrder[] = 2;
            });

            $db->onCommit(function () use (&$callOrder) {
                $callOrder[] = 3;
            });
        })->await();

        expect($callOrder)->toBe([1, 2, 3]);

        $db->execute('DROP TABLE IF EXISTS callback_test')->await();
    });

    it('executes multiple onRollback callbacks in order', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS callback_test')->await();
        $db->execute('
            CREATE TABLE callback_test (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            )
        ')->await();

        $callOrder = [];

        try {
            $db->transaction(function ($conn) use ($db, &$callOrder) {
                mysqli_query($conn, "INSERT INTO callback_test (name) VALUES ('David')");

                $db->onRollback(function () use (&$callOrder) {
                    $callOrder[] = 1;
                });

                $db->onRollback(function () use (&$callOrder) {
                    $callOrder[] = 2;
                });

                $db->onRollback(function () use (&$callOrder) {
                    $callOrder[] = 3;
                });

                throw new Exception('Force rollback');
            })->await();
        } catch (Hibla\MySQL\Exceptions\TransactionFailedException $e) {
            // Expected
        }

        expect($callOrder)->toBe([1, 2, 3]);

        $db->execute('DROP TABLE IF EXISTS callback_test')->await();
    });

    it('can use callbacks for cleanup operations', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS callback_test')->await();
        $db->execute('
            CREATE TABLE callback_test (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                temp_flag BOOLEAN DEFAULT FALSE
            )
        ')->await();

        $cleanupPerformed = false;

        $db->transaction(function ($conn) use ($db, &$cleanupPerformed) {
            mysqli_query($conn, "INSERT INTO callback_test (name, temp_flag) VALUES ('Eve', TRUE)");

            $db->onCommit(function () use ($db, &$cleanupPerformed) {
                $cleanupPerformed = true;
            });
        })->await();

        expect($cleanupPerformed)->toBeTrue();

        $db->execute('DROP TABLE IF EXISTS callback_test')->await();
    });
});