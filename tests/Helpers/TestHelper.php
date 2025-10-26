<?php

declare(strict_types=1);

namespace Tests\Helpers;

class TestHelper
{
    public static function getTestConfig(): array
    {
        return [
            'host' => $_ENV['MYSQL_HOST'],
            'username' => $_ENV['MYSQL_USERNAME'],
            'database' => $_ENV['MYSQL_DATABASE'],
            'password' => $_ENV['MYSQL_PASSWORD'],
            'port' => (int) $_ENV['MYSQL_PORT'],
            'options' => [
                MYSQLI_OPT_INT_AND_FLOAT_NATIVE => true,
            ],
        ];
    }
}
