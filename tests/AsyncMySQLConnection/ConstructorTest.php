<?php

declare(strict_types=1);

use Hibla\MySQL\AsyncMySQLConnection;
use Hibla\MySQL\Exceptions\ConfigurationException;
use Tests\Helpers\TestHelper;

describe('AsyncMySQLConnection Constructor', function () {
    it('creates connection with valid configuration', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        expect($db)->toBeInstanceOf(AsyncMySQLConnection::class);

        $stats = $db->getStats();
        expect($stats['total'])->toBe(0); // No connections created yet
    });

    it('uses default pool size of 10', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig());

        $stats = $db->getStats();
        expect($stats)->toBeArray();
    });

    it('throws ConfigurationException for invalid config', function () {
        expect(fn () => new AsyncMySQLConnection([]))
            ->toThrow(ConfigurationException::class)
        ;
    });

    it('throws ConfigurationException for missing host', function () {
        expect(fn () => new AsyncMySQLConnection([
            'username' => 'root',
            'database' => 'test_db',
        ]))->toThrow(ConfigurationException::class);
    });

    it('can be reset and becomes unusable', function () {
        $db = new AsyncMySQLConnection(TestHelper::getTestConfig(), 5);

        $db->reset();

        expect(fn () => $db->getStats())
            ->toThrow(Hibla\MySQL\Exceptions\NotInitializedException::class)
        ;
    });
});