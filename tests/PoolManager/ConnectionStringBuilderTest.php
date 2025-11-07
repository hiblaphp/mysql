<?php

declare(strict_types=1);

use Hibla\MySQL\Manager\PoolManager;

describe('PoolManager Connection String Building', function () {
    it('builds connection with minimal config', function () {
        $config = [
            'host' => 'localhost',
            'username' => 'testuser',
            'database' => 'testdb',
        ];

        $pool = new PoolManager($config, 5);

        expect($pool->getStats()['config_validated'])->toBeTrue();

        $pool->close();
    });

    it('builds connection with all parameters', function () {
        $config = [
            'host' => 'localhost',
            'username' => 'testuser',
            'database' => 'testdb',
            'password' => 'testpass',
            'port' => 3307,
            'socket' => '/tmp/mysql.sock',
            'charset' => 'utf8mb4',
        ];

        $pool = new PoolManager($config, 5);

        expect($pool->getStats()['config_validated'])->toBeTrue();

        $pool->close();
    });

    it('accepts socket connection', function () {
        $config = [
            'host' => 'localhost',
            'username' => 'root',
            'database' => 'test_db',
            'socket' => '/var/run/mysqld/mysqld.sock',
        ];

        $pool = new PoolManager($config, 5);

        expect($pool->getStats()['config_validated'])->toBeTrue();

        $pool->close();
    });
});
