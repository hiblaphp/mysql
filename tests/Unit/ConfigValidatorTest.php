<?php

declare(strict_types=1);

namespace Tests\Utilities;

use Hibla\MySQL\Utilities\ConfigValidator;
use InvalidArgumentException;

describe('ConfigValidator', function () {
    describe('validate', function () {
        it('accepts valid configuration', function () {
            $config = [
                'host' => 'localhost',
                'username' => 'root',
                'password' => 'secret',
                'database' => 'test_db',
            ];

            expect(fn() => ConfigValidator::validate($config))->not->toThrow(InvalidArgumentException::class);
        });

        it('accepts configuration with all optional fields', function () {
            $config = [
                'host' => 'localhost',
                'username' => 'root',
                'password' => 'secret',
                'database' => 'test_db',
                'port' => 3306,
                'socket' => '/tmp/mysql.sock',
                'charset' => 'utf8mb4',
                'options' => [
                    MYSQLI_OPT_CONNECT_TIMEOUT => 5,
                ],
            ];

            expect(fn() => ConfigValidator::validate($config))->not->toThrow(InvalidArgumentException::class);
        });

        it('throws exception for empty configuration', function () {
            expect(fn() => ConfigValidator::validate([]))
                ->toThrow(InvalidArgumentException::class, 'Database configuration cannot be empty');
        });

        it('throws exception for missing host', function () {
            $config = [
                'username' => 'root',
                'database' => 'test_db',
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, "Missing required database configuration field: 'host'");
        });

        it('throws exception for missing username', function () {
            $config = [
                'host' => 'localhost',
                'database' => 'test_db',
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, "Missing required database configuration field: 'username'");
        });

        it('throws exception for missing database', function () {
            $config = [
                'host' => 'localhost',
                'username' => 'root',
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, "Missing required database configuration field: 'database'");
        });

        it('throws exception for empty host', function () {
            $config = [
                'host' => '',
                'username' => 'root',
                'database' => 'test_db',
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, "Database configuration field 'host' cannot be empty");
        });

        it('throws exception for null host', function () {
            $config = [
                'host' => null,
                'username' => 'root',
                'database' => 'test_db',
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, "Database configuration field 'host' cannot be empty");
        });

        it('throws exception for empty database', function () {
            $config = [
                'host' => 'localhost',
                'username' => 'root',
                'database' => '',
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, "Database configuration field 'database' cannot be empty");
        });

        it('allows empty username', function () {
            $config = [
                'host' => 'localhost',
                'username' => '',
                'database' => 'test_db',
            ];

            expect(fn() => ConfigValidator::validate($config))->not->toThrow(InvalidArgumentException::class);
        });

        it('throws exception for non-string host', function () {
            $config = [
                'host' => 123,
                'username' => 'root',
                'database' => 'test_db',
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, 'Database host must be a string');
        });

        it('throws exception for non-string username', function () {
            $config = [
                'host' => 'localhost',
                'username' => 123,
                'database' => 'test_db',
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, 'Database username must be a string');
        });

        it('throws exception for non-string database', function () {
            $config = [
                'host' => 'localhost',
                'username' => 'root',
                'database' => 123,
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, 'Database database must be a string');
        });

        it('throws exception for non-string password', function () {
            $config = [
                'host' => 'localhost',
                'username' => 'root',
                'database' => 'test_db',
                'password' => 123,
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, 'Database password must be a string');
        });

        it('throws exception for non-string charset', function () {
            $config = [
                'host' => 'localhost',
                'username' => 'root',
                'database' => 'test_db',
                'charset' => 123,
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, 'Database charset must be a string');
        });

        it('throws exception for non-string socket', function () {
            $config = [
                'host' => 'localhost',
                'username' => 'root',
                'database' => 'test_db',
                'socket' => 123,
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, 'Database socket must be a string');
        });

        it('throws exception for non-integer port', function () {
            $config = [
                'host' => 'localhost',
                'username' => 'root',
                'database' => 'test_db',
                'port' => '3306',
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, 'Database port must be an integer');
        });

        it('throws exception for negative port', function () {
            $config = [
                'host' => 'localhost',
                'username' => 'root',
                'database' => 'test_db',
                'port' => -1,
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, 'Database port must be a positive integer, got -1');
        });

        it('throws exception for zero port', function () {
            $config = [
                'host' => 'localhost',
                'username' => 'root',
                'database' => 'test_db',
                'port' => 0,
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, 'Database port must be a positive integer, got 0');
        });

        it('accepts valid positive port', function () {
            $config = [
                'host' => 'localhost',
                'username' => 'root',
                'database' => 'test_db',
                'port' => 3306,
            ];

            expect(fn() => ConfigValidator::validate($config))->not->toThrow(InvalidArgumentException::class);
        });

        it('throws exception for non-array options', function () {
            $config = [
                'host' => 'localhost',
                'username' => 'root',
                'database' => 'test_db',
                'options' => 'invalid',
            ];

            expect(fn() => ConfigValidator::validate($config))
                ->toThrow(InvalidArgumentException::class, 'Database options must be an array');
        });

        it('accepts empty options array', function () {
            $config = [
                'host' => 'localhost',
                'username' => 'root',
                'database' => 'test_db',
                'options' => [],
            ];

            expect(fn() => ConfigValidator::validate($config))->not->toThrow(InvalidArgumentException::class);
        });
    });
});