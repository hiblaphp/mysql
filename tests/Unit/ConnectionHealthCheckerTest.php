<?php

declare(strict_types=1);

namespace Tests\Unit;

use Hibla\MySQL\Utilities\ConnectionFactory;
use Hibla\MySQL\Utilities\ConnectionHealthChecker;
use Tests\Helpers\TestHelper;

describe('ConnectionHealthChecker', function () {
    describe('isAlive', function () {
        it('returns true for active connection', function () {
            $config = TestHelper::getTestConfig();
            $mysqli = ConnectionFactory::create($config);

            $isAlive = ConnectionHealthChecker::isAlive($mysqli);

            expect($isAlive)->toBeTrue();

            $mysqli->close();
        });

        it('returns false for closed connection', function () {
            $config = TestHelper::getTestConfig();
            $mysqli = ConnectionFactory::create($config);
            $mysqli->close();

            $isAlive = ConnectionHealthChecker::isAlive($mysqli);

            expect($isAlive)->toBeFalse();
        });

        it('handles connection with active transaction', function () {
            $config = TestHelper::getTestConfig();
            $mysqli = ConnectionFactory::create($config);

            $mysqli->begin_transaction();
            $isAlive = ConnectionHealthChecker::isAlive($mysqli);

            expect($isAlive)->toBeTrue();

            $mysqli->rollback();
            $mysqli->close();
        });
    });

    describe('reset', function () {
        it('clears pending results', function () {
            $config = TestHelper::getTestConfig();
            $mysqli = ConnectionFactory::create($config);

            $mysqli->query('DROP TABLE IF EXISTS test_reset');
            $mysqli->query('CREATE TABLE test_reset (id INT)');
            $mysqli->query('INSERT INTO test_reset (id) VALUES (1), (2), (3)');

            $mysqli->multi_query('SELECT * FROM test_reset; SELECT * FROM test_reset;');
            
            ConnectionHealthChecker::reset($mysqli);

            $result = $mysqli->query('SELECT 1 as test');
            expect($result)->not->toBeFalse();

            $mysqli->query('DROP TABLE IF EXISTS test_reset');
            $mysqli->close();
        });

        it('ensures autocommit is enabled', function () {
            $config = TestHelper::getTestConfig();
            $mysqli = ConnectionFactory::create($config);

            $mysqli->autocommit(false);
            $autocommitBefore = $mysqli->query('SELECT @@autocommit')->fetch_row()[0];
            expect((int)$autocommitBefore)->toBe(0);

            ConnectionHealthChecker::reset($mysqli);

            $result = $mysqli->query('SELECT @@autocommit');
            $autocommit = $result->fetch_row()[0];
            expect((int)$autocommit)->toBe(1);

            $mysqli->close();
        });

        it('handles connection in transaction', function () {
            $config = TestHelper::getTestConfig();
            $mysqli = ConnectionFactory::create($config);

            $mysqli->query('DROP TABLE IF EXISTS test_transaction');
            $mysqli->query('CREATE TABLE test_transaction (id INT)');

            $mysqli->begin_transaction();
            $mysqli->query('INSERT INTO test_transaction (id) VALUES (1)');

            ConnectionHealthChecker::reset($mysqli);

            $result = $mysqli->query('SELECT @@autocommit');
            $autocommit = $result->fetch_row()[0];
            expect((int)$autocommit)->toBe(1);

            $mysqli->query('DROP TABLE IF EXISTS test_transaction');
            $mysqli->close();
        });

        it('handles already clean connection', function () {
            $config = TestHelper::getTestConfig();
            $mysqli = ConnectionFactory::create($config);

            ConnectionHealthChecker::reset($mysqli);

            $result = $mysqli->query('SELECT 1 as test');
            expect($result)->not->toBeFalse();
            
            $testValue = $result->fetch_assoc()['test'];
            expect((int)$testValue)->toBe(1);

            $mysqli->close();
        });

        it('does not throw on closed connection', function () {
            $config = TestHelper::getTestConfig();
            $mysqli = ConnectionFactory::create($config);
            $mysqli->close();

            expect(fn() => ConnectionHealthChecker::reset($mysqli))->not->toThrow(\Exception::class);
        });
    });

    describe('integration', function () {
        it('can detect and reset unhealthy connection', function () {
            $config = TestHelper::getTestConfig();
            $mysqli = ConnectionFactory::create($config);

            $mysqli->multi_query('SELECT 1; SELECT 2; SELECT 3;');

            expect(ConnectionHealthChecker::isAlive($mysqli))->toBeTrue();

            ConnectionHealthChecker::reset($mysqli);

            $result = $mysqli->query('SELECT 1 as test');
            $testValue = $result->fetch_assoc()['test'];
            expect((int)$testValue)->toBe(1);

            $mysqli->close();
        });
    });
});