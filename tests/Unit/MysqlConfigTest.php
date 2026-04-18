<?php

declare(strict_types=1);

namespace Hibla\Mysql\Tests\ValueObjects;

use Hibla\Mysql\ValueObjects\MysqlConfig;

describe('MysqlConfig', function (): void {

    it('initializes with correct default values', function (): void {
        $config = new MysqlConfig(host: 'localhost');

        expect($config->host)->toBe('localhost')
            ->and($config->port)->toBe(3306)
            ->and($config->username)->toBe('root')
            ->and($config->password)->toBe('')
            ->and($config->database)->toBe('')
            ->and($config->charset)->toBe('utf8mb4')
            ->and($config->ssl)->toBeFalse()
            ->and($config->enableServerSideCancellation)->toBeFalse()
            ->and($config->multiStatements)->toBeFalse();
    });

    it('throws exception if killTimeoutSeconds is zero or negative', function (): void {
        expect(fn() => new MysqlConfig(host: 'localhost', killTimeoutSeconds: 0))
            ->toThrow(\InvalidArgumentException::class, 'killTimeoutSeconds must be greater than zero');

        expect(fn() => new MysqlConfig(host: 'localhost', killTimeoutSeconds: -1.5))
            ->toThrow(\InvalidArgumentException::class, 'killTimeoutSeconds must be greater than zero');
    });

    describe('fromArray', function (): void {
        it('creates instance from a valid array', function (): void {
            $data = [
                'host' => '127.0.0.1',
                'port' => 3307,
                'username' => 'dbuser',
                'password' => 'secret',
                'database' => 'test_db',
                'ssl' => true,
                'compress' => true,
                'reset_connection' => true,
                'multi_statements' => true,
            ];

            $config = MysqlConfig::fromArray($data);

            expect($config->host)->toBe('127.0.0.1')
                ->and($config->port)->toBe(3307)
                ->and($config->username)->toBe('dbuser')
                ->and($config->password)->toBe('secret')
                ->and($config->database)->toBe('test_db')
                ->and($config->ssl)->toBeTrue()
                ->and($config->compress)->toBeTrue()
                ->and($config->resetConnection)->toBeTrue()
                ->and($config->multiStatements)->toBeTrue();
        });

        it('throws exception if host is missing in array', function (): void {
            expect(fn() => MysqlConfig::fromArray(['port' => 3306]))
                ->toThrow(\InvalidArgumentException::class, 'Host is required');
        });

        it('casts array values to correct types', function (): void {
            $config = MysqlConfig::fromArray([
                'host' => 'localhost',
                'port' => '3308',
                'ssl' => 1,
                'connect_timeout' => '15',
            ]);

            expect($config->port)->toBe(3308)
                ->and($config->ssl)->toBeTrue()
                ->and($config->connectTimeout)->toBe(15);
        });
    });

    describe('fromUri', function (): void {
        it('parses a full MySQL URI', function (): void {
            $uri = 'mysql://user:pass@remote-host:3309/my_database?charset=latin1&ssl=true&compress=1';
            $config = MysqlConfig::fromUri($uri);

            expect($config->host)->toBe('remote-host')
                ->and($config->port)->toBe(3309)
                ->and($config->username)->toBe('user')
                ->and($config->password)->toBe('pass')
                ->and($config->database)->toBe('my_database')
                ->and($config->charset)->toBe('latin1')
                ->and($config->ssl)->toBeTrue()
                ->and($config->compress)->toBeTrue();
        });

        it('automatically prepends mysql:// scheme if missing', function (): void {
            $config = MysqlConfig::fromUri('localhost/mydb');
            expect($config->host)->toBe('localhost')
                ->and($config->database)->toBe('mydb');
        });

        it('handles URL encoded characters in user and password', function (): void {
            $uri = 'mysql://admin%3A1:p%40ssw%23rd@localhost';
            $config = MysqlConfig::fromUri($uri);

            expect($config->username)->toBe('admin:1')
                ->and($config->password)->toBe('p@ssw#rd');
        });

        it('throws exception for invalid URI scheme', function (): void {
            expect(fn() => MysqlConfig::fromUri('postgres://localhost'))
                ->toThrow(\InvalidArgumentException::class, 'Invalid URI scheme "postgres", expected "mysql"');
        });

        it('correctly parses boolean query parameters', function (): void {
            $uri = 'mysql://localhost?reset_connection=true&multi_statements=false&enable_server_side_cancellation=0';
            $config = MysqlConfig::fromUri($uri);

            expect($config->resetConnection)->toBeTrue()
                ->and($config->multiStatements)->toBeFalse()
                ->and($config->enableServerSideCancellation)->toBeFalse();
        });
    });

    describe('Helpers and Logic', function (): void {
        it('correctly reports hasPassword', function (): void {
            expect((new MysqlConfig('host'))->hasPassword())->toBeFalse()
                ->and((new MysqlConfig('host', password: 'abc'))->hasPassword())->toBeTrue();
        });

        it('correctly reports hasDatabase', function (): void {
            expect((new MysqlConfig('host'))->hasDatabase())->toBeFalse()
                ->and((new MysqlConfig('host', database: 'db'))->hasDatabase())->toBeTrue();
        });

        it('correctly reports useSsl', function (): void {
            expect((new MysqlConfig('host'))->useSsl())->toBeFalse()
                ->and((new MysqlConfig('host', ssl: true))->useSsl())->toBeTrue();
        });

        it('creates a new instance with withQueryCancellation', function (): void {
            $config = new MysqlConfig('host', enableServerSideCancellation: false);
            $newConfig = $config->withQueryCancellation(true);

            expect($config->enableServerSideCancellation)->toBeFalse()
                ->and($newConfig->enableServerSideCancellation)->toBeTrue()
                ->and($newConfig)->not->toBe($config); 
        });

        it('sanitizes the password in toSafeUri', function (): void {
            $config = new MysqlConfig(
                host: '10.0.0.1',
                port: 3306,
                username: 'root',
                password: 'super-secret-password',
                database: 'production'
            );

            $safeUri = $config->toSafeUri();

            expect($safeUri)->not->toContain('***')
                ->and($safeUri)->not->toContain('super-secret-password')
                ->and($safeUri)->toBe('mysql://10.0.0.1/production');

            $configCustom = new MysqlConfig(
                host: 'localhost',
                username: 'admin',
                password: 'secret-password'
            );

            $customSafeUri = $configCustom->toSafeUri();

            expect($customSafeUri)->toContain('admin:***@')
                ->and($customSafeUri)->not->toContain('secret-password')
                ->and($customSafeUri)->toBe('mysql://admin:***@localhost');
        });
    });
});
