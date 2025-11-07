<?php

declare(strict_types=1);

use Hibla\MySQL\AsyncMySQLConnection;
use Hibla\Promise\Promise;

require 'vendor/autoload.php';

$client = new AsyncMySQLConnection([
    'host' => 'localhost',
    'port' => 3309,
    'database' => 'horses',
    'username' => 'root',
    'password' => 'Reymart1234',
], 10);

$startTime = microtime(true);
Promise::all([
    $client->execute('SELECT SLEEP(1)'),
    $client->execute('SELECT SLEEP(1)'),
    $client->execute('SELECT SLEEP(1)'),
])->await();
$duration = microtime(true) - $startTime;
echo "Total duration: $duration seconds\n";
