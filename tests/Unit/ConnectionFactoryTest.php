<?php

declare(strict_types=1);

namespace Tests\Unit;

use Hibla\MySQL\Utilities\ConnectionFactory;
use RuntimeException;
use Tests\Helpers\TestHelper;

describe('ConnectionFactory', function () {
    describe('create', function () {
        it('creates connection with valid configuration', function () {
            $config = TestHelper::getTestConfig();
            $mysqli = ConnectionFactory::create($config);

            expect($mysqli)->toBeInstanceOf(\mysqli::class);
            expect($mysqli->connect_error)->toBeNull();

            $mysqli->close();
        });

        it('creates connection with port specified', function () {
            $config = TestHelper::getTestConfig();
            $config['port'] = 3306;

            $mysqli = ConnectionFactory::create($config);

            expect($mysqli)->toBeInstanceOf(\mysqli::class);
            expect($mysqli->connect_error)->toBeNull();

            $mysqli->close();
        });

        it('creates connection with charset', function () {
            $config = TestHelper::getTestConfig();
            $config['charset'] = 'utf8mb4';

            $mysqli = ConnectionFactory::create($config);

            expect($mysqli)->toBeInstanceOf(\mysqli::class);

            $result = $mysqli->query('SELECT @@character_set_connection');
            $row = $result->fetch_row();
            expect($row[0])->toBe('utf8mb4');

            $mysqli->close();
        });

        it('creates connection with options', function () {
            $config = TestHelper::getTestConfig();
            $config['options'] = [
                MYSQLI_OPT_CONNECT_TIMEOUT => 5,
                MYSQLI_OPT_INT_AND_FLOAT_NATIVE => true,
            ];

            $mysqli = ConnectionFactory::create($config);

            expect($mysqli)->toBeInstanceOf(\mysqli::class);
            expect($mysqli->connect_error)->toBeNull();

            $mysqli->close();
        });

        it('throws exception for invalid host', function () {
            $config = TestHelper::getTestConfig();
            $config['host'] = 'invalid-host-that-does-not-exist';

            $exception = null;

            try {
                ConnectionFactory::create($config);
            } catch (RuntimeException $e) {
                $exception = $e;
            }

            expect($exception)->toBeInstanceOf(RuntimeException::class);
            expect($exception->getMessage())->toContain('MySQLi Connection failed');
        })->skip('Skip to avoid warning about invalid host');

        it('throws exception for invalid credentials', function () {
            $config = TestHelper::getTestConfig();
            $config['username'] = 'invalid_user';
            $config['password'] = 'invalid_password';

            $exception = null;

            try {
                ConnectionFactory::create($config);
            } catch (RuntimeException $e) {
                $exception = $e;
            }

            expect($exception)->toBeInstanceOf(RuntimeException::class);
            expect($exception->getMessage())->toContain('MySQLi Connection failed');
        });

        it('throws exception for invalid database', function () {
            $config = TestHelper::getTestConfig();
            $config['database'] = 'non_existent_database_12345';

            $exception = null;

            try {
                ConnectionFactory::create($config);
            } catch (RuntimeException $e) {
                $exception = $e;
            }

            expect($exception)->toBeInstanceOf(RuntimeException::class);
            expect($exception->getMessage())->toContain('MySQLi Connection failed');
        });

        it('throws exception for invalid charset', function () {
            $config = TestHelper::getTestConfig();
            $config['charset'] = 'invalid_charset';

            $exception = null;

            try {
                ConnectionFactory::create($config);
            } catch (RuntimeException $e) {
                $exception = $e;
            }

            expect($exception)->toBeInstanceOf(RuntimeException::class);
            expect($exception->getMessage())->toContain('Failed to set charset');
        });

        it('handles connection with password', function () {
            $config = TestHelper::getTestConfig();
            // Use the password from config (should work)

            $mysqli = ConnectionFactory::create($config);

            expect($mysqli)->toBeInstanceOf(\mysqli::class);

            $mysqli->close();
        });

        it('creates multiple independent connections', function () {
            $config = TestHelper::getTestConfig();

            $mysqli1 = ConnectionFactory::create($config);
            $mysqli2 = ConnectionFactory::create($config);

            expect($mysqli1)->toBeInstanceOf(\mysqli::class);
            expect($mysqli2)->toBeInstanceOf(\mysqli::class);
            expect($mysqli1)->not->toBe($mysqli2);

            $mysqli1->close();
            $mysqli2->close();
        });
    });
});
