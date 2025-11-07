<?php

declare(strict_types=1);

use Hibla\MySQL\AsyncMySQLConnection;

expect()->extend('toBeThreadId', function () {
    return $this->toBeInt()->toBeGreaterThan(0);
});

expect()->extend('toBeConnectionId', function () {
    return $this->toBeInt()->toBeGreaterThan(0);
});

function createPersistentConnection(int $poolSize = 10): AsyncMySQLConnection
{
    $isCI = (bool) getenv('CI');

    $defaultHost = $isCI ? '127.0.0.1' : 'localhost';

    $config = [
        'driver' => 'mysql',
        'host' => $defaultHost,
        'port' => (int) (getenv('MYSQL_PORT') ?: 3306),
        'database' => getenv('MYSQL_DATABASE') ?: 'test',
        'username' => getenv('MYSQL_USERNAME') ?: 'root',
        'password' => getenv('MYSQL_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        'persistent' => true,
    ];

    return new AsyncMySQLConnection($config, $poolSize);
}

function createRegularConnection(int $poolSize = 10): AsyncMySQLConnection
{
    $isCI = (bool) getenv('CI');

    $defaultHost = $isCI ? '127.0.0.1' : 'localhost';

    $config = [
        'driver' => 'mysql',
        'host' => $defaultHost,
        'port' => (int) (getenv('MYSQL_PORT') ?: 3306),
        'database' => getenv('MYSQL_DATABASE') ?: 'test',
        'username' => getenv('MYSQL_USERNAME') ?: 'root',
        'password' => getenv('MYSQL_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        'persistent' => false,
    ];

    return new AsyncMySQLConnection($config, $poolSize);
}

function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false) {
        return $default;
    }

    return $value;
}
