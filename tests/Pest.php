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
    return new AsyncMySQLConnection([
        'host' => env('MYSQL_HOST', 'localhost'),
        'port' => (int) env('MYSQL_PORT', 3306),
        'database' => env('MYSQL_DATABASE', 'test'),
        'username' => env('MYSQL_USERNAME', 'root'),
        'password' => env('MYSQL_PASSWORD', ''),
        'persistent' => true,
    ], $poolSize);
}

function createRegularConnection(int $poolSize = 10): AsyncMySQLConnection
{
    return new AsyncMySQLConnection([
        'host' => env('MYSQL_HOST', 'localhost'),
        'port' => (int) env('MYSQL_PORT', 3306),
        'database' => env('MYSQL_DATABASE', 'test'),
        'username' => env('MYSQL_USERNAME', 'root'),
        'password' => env('MYSQL_PASSWORD', ''),
        'persistent' => false,
    ], $poolSize);
}

function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    
    if ($value === false) {
        return $default;
    }
    
    return $value;
}