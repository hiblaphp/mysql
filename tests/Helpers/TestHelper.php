<?php

declare(strict_types=1);

namespace Tests\Helpers;

class TestHelper
{
    public static function getTestConfig(): array
    {
        $isCI = (bool) getenv('CI');

        $defaultHost = $isCI ? '127.0.0.1' : 'localhost';

        return [
            'driver' => 'mysql',
            'host' => $defaultHost,
            'port' => (int) (getenv('MYSQL_PORT') ?: 3306),
            'database' => getenv('MYSQL_DATABASE') ?: 'test',
            'username' => getenv('MYSQL_USERNAME') ?: 'root',
            'password' => getenv('MYSQL_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'options' => [
                MYSQLI_OPT_INT_AND_FLOAT_NATIVE => true,
            ]           
        ];
    }
}
