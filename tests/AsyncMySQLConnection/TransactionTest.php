<?php

declare(strict_types=1);

use Hibla\MySQL\AsyncMySQLConnection;
use Hibla\MySQL\Enums\IsolationLevel;
use Hibla\MySQL\Exceptions\TransactionFailedException;
use Hibla\Promise\Promise;

use function Hibla\sleep;

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

        $result = $db->transaction(function ($trx) {
            $trx->execute('INSERT INTO accounts (name, balance) VALUES (?, ?)', ['Alice', 1000.00], 'sd');
            $trx->execute('INSERT INTO accounts (name, balance) VALUES (?, ?)', ['Bob', 2000.00], 'sd');

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
            $db->transaction(function ($trx) {
                $trx->execute('INSERT INTO accounts (name, balance) VALUES (?, ?)', ['Charlie', 500.00], 'sd');

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

        $db->transaction(function ($trx) {
            $transferAmount = 300.00;

            $trx->execute('UPDATE accounts SET balance = balance - ? WHERE name = ?', [$transferAmount, 'Alice'], 'ds');
            $trx->execute('UPDATE accounts SET balance = balance + ? WHERE name = ?', [$transferAmount, 'Bob'], 'ds');
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
            $db->transaction(function ($trx) use (&$attempts) {
                $attempts++;

                $trx->execute('INSERT INTO accounts (name, balance) VALUES (?, ?)', ['David', 100.00], 'sd');

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
            $db->transaction(function ($trx) use (&$attempts) {
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

        $insertedId = $db->transaction(function ($trx) {
            $trx->execute('INSERT INTO accounts (name, balance) VALUES (?, ?)', ['Eve', 750.00], 'sd');
            $insertedId = $trx->fetchValue('SELECT LAST_INSERT_ID()');

            return $insertedId;
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

        $db->transaction(function ($trx) {
            $trx->execute('INSERT INTO accounts (name, balance) VALUES (?, ?)', ['Frank', 1500.00], 'sd');

            $account = $trx->fetchOne('SELECT * FROM accounts WHERE name = ?', ['Frank'], 's');

            expect($account['balance'])->toBe('1500.00');

            $trx->execute('UPDATE accounts SET balance = ? WHERE name = ?', [2000.00, 'Frank'], 'ds');
        })->await();

        $balance = $db->fetchValue("SELECT balance FROM accounts WHERE name = 'Frank'")->await();
        expect($balance)->toBe('2000.00');

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
    });

    it('enforces isolation by preventing concurrent updates to same row', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
        $db->execute('
        CREATE TABLE accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            balance DECIMAL(10, 2) NOT NULL DEFAULT 0,
            INDEX idx_name (name)
        ) ENGINE=InnoDB')->await();

        $db->execute("INSERT INTO accounts (name, balance) VALUES ('Grace', 1000.00)")->await();

        $db->execute('SET SESSION innodb_lock_wait_timeout = 2')->await();

        $executionLog = [];

        $promise1 = $db->transaction(function ($trx) use (&$executionLog) {
            $trx->execute('SET SESSION innodb_lock_wait_timeout = 2');

            $row = $trx->fetchOne('SELECT balance FROM accounts WHERE name = ? FOR UPDATE', ['Grace'], 's');
            $executionLog[] = 'T1-locked';
            $currentBalance = (float)$row['balance'];
            Hibla\sleep(3);

            $newBalance = $currentBalance + 100;
            $trx->execute('UPDATE accounts SET balance = ? WHERE name = ?', [$newBalance, 'Grace'], 'ds');
            $executionLog[] = 'T1-complete';

            return $currentBalance + 100;
        });

        $promise2 = $db->transaction(function ($trx) use (&$executionLog) {
            $trx->execute('SET SESSION innodb_lock_wait_timeout = 2');

            $executionLog[] = 'T2-attempting';
            $row = $trx->fetchOne('SELECT balance FROM accounts WHERE name = ? FOR UPDATE', ['Grace'], 's');
            $executionLog[] = 'T2-locked';
            $currentBalance = (float)$row['balance'];

            $newBalance = $currentBalance + 200;
            $trx->execute('UPDATE accounts SET balance = ? WHERE name = ?', [$newBalance, 'Grace'], 'ds');

            return $currentBalance + 200;
        });

        expect(fn () => Promise::all([$promise1, $promise2])->await())
            ->toThrow(TransactionFailedException::class)
        ;

        expect($executionLog)->toContain('T1-locked')
            ->and($executionLog)->toContain('T2-attempting')
            ->and($executionLog)->not->toContain('T2-locked')
        ;

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
    });

    it('isolates transactions across concurrent operations', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
        $db->execute('
        CREATE TABLE accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            balance DECIMAL(10, 2) NOT NULL DEFAULT 0,
            INDEX idx_name (name)
        ) ENGINE=InnoDB
    ')->await();

        $db->execute("INSERT INTO accounts (name, balance) VALUES ('Grace', 1000.00)")->await();
        $db->execute("INSERT INTO accounts (name, balance) VALUES ('Henry', 2000.00)")->await();

        $executionLog = [];
        $startTime = microtime(true);

        $promise1 = $db->transaction(function ($trx) use (&$executionLog) {
            $row = $trx->fetchOne('SELECT balance FROM accounts WHERE name = ? FOR UPDATE', ['Grace'], 's');
            $executionLog[] = 'T1-start';

            Hibla\sleep(0.1);

            $newBalance = (float)$row['balance'] + 100;
            $trx->execute('UPDATE accounts SET balance = ? WHERE name = ?', [$newBalance, 'Grace'], 'ds');
            $executionLog[] = 'T1-end';
        });

        $promise2 = $db->transaction(function ($trx) use (&$executionLog) {
            $row = $trx->fetchOne('SELECT balance FROM accounts WHERE name = ? FOR UPDATE', ['Henry'], 's');
            $executionLog[] = 'T2-start';

            Hibla\sleep(0.1);

            $newBalance = (float)$row['balance'] + 200;
            $trx->execute('UPDATE accounts SET balance = ? WHERE name = ?', [$newBalance, 'Henry'], 'ds');
            $executionLog[] = 'T2-end';
        });

        Promise::all([$promise1, $promise2])->await();

        $duration = microtime(true) - $startTime;

        expect($duration)->toBeLessThan(0.18);

        expect($executionLog)->toHaveCount(4);

        $graceBalance = $db->fetchValue("SELECT balance FROM accounts WHERE name = 'Grace'")->await();
        $henryBalance = $db->fetchValue("SELECT balance FROM accounts WHERE name = 'Henry'")->await();

        expect((float)$graceBalance)->toBe(1100.00)
            ->and((float)$henryBalance)->toBe(2200.00)
        ;

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
    });

    it('executes truly concurrent transactions on different rows', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
        $db->execute('
        CREATE TABLE accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            balance DECIMAL(10, 2) NOT NULL DEFAULT 0,
            INDEX idx_name (name)
        ) ENGINE=InnoDB')->await();

        $db->execute("INSERT INTO accounts (name, balance) VALUES ('Grace', 1000.00)")->await();
        $db->execute("INSERT INTO accounts (name, balance) VALUES ('Henry', 2000.00)")->await();

        $executionLog = [];
        $startTime = microtime(true);

        $promise1 = $db->transaction(function ($trx) use (&$executionLog) {
            $row = $trx->fetchOne('SELECT balance FROM accounts WHERE name = ? FOR UPDATE', ['Grace'], 's');
            $executionLog[] = 'T1-start';

            $newBalance = (float)$row['balance'] + 100;
            $trx->execute('UPDATE accounts SET balance = ? WHERE name = ?', [$newBalance, 'Grace'], 'ds');
            $executionLog[] = 'T1-end';
        });

        $promise2 = $db->transaction(function ($trx) use (&$executionLog) {
            $row = $trx->fetchOne('SELECT balance FROM accounts WHERE name = ? FOR UPDATE', ['Henry'], 's');
            $executionLog[] = 'T2-start';

            $newBalance = (float)$row['balance'] + 200;
            $trx->execute('UPDATE accounts SET balance = ? WHERE name = ?', [$newBalance, 'Henry'], 'ds');
            $executionLog[] = 'T2-end';
        });

        Promise::all([$promise1, $promise2])->await();

        $duration = microtime(true) - $startTime;

        expect($duration)->toBeLessThan(0.18);

        expect($executionLog)->toContain('T1-start')
            ->and($executionLog)->toContain('T2-start')
            ->and($executionLog)->toContain('T1-end')
            ->and($executionLog)->toContain('T2-end')
        ;

        $graceBalance = $db->fetchValue("SELECT balance FROM accounts WHERE name = 'Grace'")->await();
        $henryBalance = $db->fetchValue("SELECT balance FROM accounts WHERE name = 'Henry'")->await();

        expect((float)$graceBalance)->toBe(1100.00)
            ->and((float)$henryBalance)->toBe(2200.00)
        ;

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
    });

    it('executes onCommit callback', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
        $db->execute('
            CREATE TABLE accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                balance DECIMAL(10, 2) NOT NULL DEFAULT 0
            )
        ')->await();

        $commitCalled = false;

        $db->transaction(function ($trx) use (&$commitCalled) {
            $trx->execute('INSERT INTO accounts (name, balance) VALUES (?, ?)', ['Helen', 500.00], 'sd');

            $trx->onCommit(function () use (&$commitCalled) {
                $commitCalled = true;
            });
        })->await();

        expect($commitCalled)->toBeTrue();

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
    });

    it('executes onRollback callback', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
        $db->execute('
            CREATE TABLE accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                balance DECIMAL(10, 2) NOT NULL DEFAULT 0
            )
        ')->await();

        $rollbackCalled = false;

        try {
            $db->transaction(function ($trx) use (&$rollbackCalled) {
                $trx->execute('INSERT INTO accounts (name, balance) VALUES (?, ?)', ['Ivan', 300.00], 'sd');

                $trx->onRollback(function () use (&$rollbackCalled) {
                    $rollbackCalled = true;
                });

                throw new Exception('Force rollback');
            })->await();
        } catch (TransactionFailedException $e) {
            // Expected
        }

        expect($rollbackCalled)->toBeTrue();

        $db->execute('DROP TABLE IF EXISTS accounts')->await();
    });

    it('uses SERIALIZABLE isolation level when specified', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $level = $db->transaction(function ($trx) {
            return $trx->fetchValue('SELECT @@transaction_isolation');
        }, isolationLevel: IsolationLevel::SERIALIZABLE)->await();

        expect($level)->toBe('SERIALIZABLE');
    });

    it('uses READ COMMITTED isolation level when specified', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $level = $db->transaction(function ($trx) {
            return $trx->fetchValue('SELECT @@transaction_isolation');
        }, isolationLevel: IsolationLevel::READ_COMMITTED)->await();

        expect($level)->toBe('READ-COMMITTED');
    });

    it('uses READ UNCOMMITTED isolation level when specified', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $level = $db->transaction(function ($trx) {
            return $trx->fetchValue('SELECT @@transaction_isolation');
        }, isolationLevel: IsolationLevel::READ_UNCOMMITTED)->await();

        expect($level)->toBe('READ-UNCOMMITTED');
    });

    it('defaults to REPEATABLE READ isolation level when not specified', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $level = $db->transaction(function ($trx) {
            return $trx->fetchValue('SELECT @@transaction_isolation');
        })->await();

        expect($level)->toBe('REPEATABLE-READ');
    });

    it('resets isolation level to REPEATABLE READ after SERIALIZABLE transaction', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $level1 = $db->transaction(function ($trx) {
            return $trx->fetchValue('SELECT @@transaction_isolation');
        }, isolationLevel: IsolationLevel::SERIALIZABLE)->await();

        expect($level1)->toBe('SERIALIZABLE');

        $level2 = $db->transaction(function ($trx) {
            return $trx->fetchValue('SELECT @@transaction_isolation');
        })->await();

        expect($level2)->toBe('REPEATABLE-READ');
    });

    it('resets isolation level to REPEATABLE READ after READ COMMITTED transaction', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $level1 = $db->transaction(function ($trx) {
            return $trx->fetchValue('SELECT @@transaction_isolation');
        }, isolationLevel: IsolationLevel::READ_COMMITTED)->await();

        expect($level1)->toBe('READ-COMMITTED');

        $level2 = $db->transaction(function ($trx) {
            return $trx->fetchValue('SELECT @@transaction_isolation');
        })->await();

        expect($level2)->toBe('REPEATABLE-READ');
    });

    it('maintains different isolation levels across sequential transactions', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $levels = [];

        $levels[] = $db->transaction(function ($trx) {
            return $trx->fetchValue('SELECT @@transaction_isolation');
        }, isolationLevel: IsolationLevel::SERIALIZABLE)->await();

        $levels[] = $db->transaction(function ($trx) {
            return $trx->fetchValue('SELECT @@transaction_isolation');
        })->await();

        $levels[] = $db->transaction(function ($trx) {
            return $trx->fetchValue('SELECT @@transaction_isolation');
        }, isolationLevel: IsolationLevel::READ_COMMITTED)->await();

        $levels[] = $db->transaction(function ($trx) {
            return $trx->fetchValue('SELECT @@transaction_isolation');
        })->await();

        expect($levels)->toBe([
            'SERIALIZABLE',
            'REPEATABLE-READ',
            'READ-COMMITTED',
            'REPEATABLE-READ',
        ]);
    });

    it('prevents isolation level pollution across concurrent transactions', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $promise1 = $db->transaction(function ($trx) {
            $level = $trx->fetchValue('SELECT @@transaction_isolation');
            sleep(0.1);

            return $level;
        }, isolationLevel: IsolationLevel::SERIALIZABLE);

        $promise2 = $db->transaction(function ($trx) {
            $level = $trx->fetchValue('SELECT @@transaction_isolation');

            return $level;
        });

        $results = Promise::all([$promise1, $promise2])->await();

        expect($results[0])->toBe('SERIALIZABLE')
            ->and($results[1])->toBe('REPEATABLE-READ');
    });
});
