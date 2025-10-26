<?php

declare(strict_types=1);

use Hibla\MySQL\Exceptions\NotInitializedException;
use Hibla\MySQL\MySQL;
use Tests\Helpers\TestHelper;

describe('MySQL Facade', function () {
    beforeEach(function () {
        MySQL::reset();
    });

    afterEach(function () {
        MySQL::reset();
    });

    it('requires initialization before use', function () {
        expect(fn () => MySQL::query('SELECT 1')->await())
            ->toThrow(NotInitializedException::class)
        ;
    });

    it('initializes with configuration', function () {
        MySQL::init(TestHelper::getTestConfig(), 5);

        $stats = MySQL::getStats();
        expect($stats)->toBeArray();
    });

    it('ignores multiple init calls', function () {
        MySQL::init(TestHelper::getTestConfig(), 5);
        MySQL::init(TestHelper::getTestConfig(), 10);

        $stats = MySQL::getStats();
        expect($stats)->toBeArray();
    });

    it('delegates query to underlying instance', function () {
        MySQL::init(TestHelper::getTestConfig());

        $result = MySQL::query('SELECT 1 as num')->await();

        expect($result)->toBeArray()
            ->and($result[0]['num'])->toBe(1)
        ;
    });

    it('delegates fetchOne to underlying instance', function () {
        MySQL::init(TestHelper::getTestConfig());

        $result = MySQL::fetchOne('SELECT 1 as num')->await();

        expect($result)->toBeArray()
            ->and($result['num'])->toBe(1)
        ;
    });

    it('delegates fetchValue to underlying instance', function () {
        MySQL::init(TestHelper::getTestConfig());

        $result = MySQL::fetchValue('SELECT 42')->await();

        expect($result)->toBe(42);
    });

    it('delegates execute to underlying instance', function () {
        MySQL::init(TestHelper::getTestConfig());

        MySQL::execute('CREATE TEMPORARY TABLE test_mysql (id INT)')->await();
        $affected = MySQL::execute('INSERT INTO test_mysql VALUES (1)')->await();

        expect($affected)->toBe(1);
    });

    it('delegates transaction to underlying instance', function () {
        MySQL::init(TestHelper::getTestConfig());

        MySQL::execute('CREATE TEMPORARY TABLE test_mysql (value INT)')->await();

        $result = MySQL::transaction(function ($conn) {
            mysqli_query($conn, 'INSERT INTO test_mysql VALUES (100)');

            return 'done';
        })->await();

        expect($result)->toBe('done');

        $count = MySQL::fetchValue('SELECT COUNT(*) FROM test_mysql')->await();
        expect($count)->toBe(1);
    });

    it('delegates onCommit to underlying instance', function () {
        MySQL::init(TestHelper::getTestConfig());

        MySQL::execute('CREATE TEMPORARY TABLE test_mysql (value INT)')->await();

        $tracker = new class () {
            public bool $called = false;
        };

        MySQL::transaction(function ($conn) use ($tracker) {
            mysqli_query($conn, 'INSERT INTO test_mysql VALUES (1)');
            MySQL::onCommit(function () use ($tracker) {
                $tracker->called = true;
            });
        })->await();

        expect($tracker->called)->toBeTrue();
    });

    it('delegates onRollback to underlying instance', function () {
        MySQL::init(TestHelper::getTestConfig());

        MySQL::execute('CREATE TEMPORARY TABLE test_mysql (value INT)')->await();

        $tracker = new class () {
            public bool $called = false;
        };

        try {
            MySQL::transaction(function ($conn) use ($tracker) {
                mysqli_query($conn, 'INSERT INTO test_mysql VALUES (1)');
                MySQL::onRollback(function () use ($tracker) {
                    $tracker->called = true;
                });

                throw new Exception('Force rollback');
            })->await();
        } catch (Hibla\MySQL\Exceptions\TransactionFailedException $e) {
            // Expected
        }

        expect($tracker->called)->toBeTrue();
    });

    it('delegates run to underlying instance', function () {
        MySQL::init(TestHelper::getTestConfig());

        $result = MySQL::run(function ($conn) {
            return mysqli_get_server_info($conn);
        })->await();

        expect($result)->toBeString();
    });

    it('delegates getLastConnection to underlying instance', function () {
        MySQL::init(TestHelper::getTestConfig());

        expect(MySQL::getLastConnection())->toBeNull();

        MySQL::query('SELECT 1')->await();

        expect(MySQL::getLastConnection())->toBeInstanceOf(mysqli::class);
    });

    it('resets properly and becomes unusable', function () {
        MySQL::init(TestHelper::getTestConfig());

        MySQL::query('SELECT 1')->await();

        MySQL::reset();

        expect(fn () => MySQL::query('SELECT 1')->await())
            ->toThrow(NotInitializedException::class)
        ;
    });
});