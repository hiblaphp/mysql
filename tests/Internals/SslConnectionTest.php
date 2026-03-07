<?php

declare(strict_types=1);

use function Hibla\await;
use Hibla\Mysql\Internals\Connection;
use Hibla\Mysql\ValueObjects\MysqlConfig;
use Hibla\Sql\Exceptions\ConnectionException;

describe('Real SSL/TLS Connection', function (): void {

    it('fails to connect without SSL when require_secure_transport is ON', function (): void {
        $config = MysqlConfig::fromArray([
            'host' => $_ENV['MYSQL_SSL_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['MYSQL_SSL_PORT'] ?? 3307),
            'database' => $_ENV['MYSQL_SSL_DATABASE'] ?? 'test',
            'username' => $_ENV['MYSQL_SSL_USERNAME'] ?? 'test_user',
            'password' => $_ENV['MYSQL_SSL_PASSWORD'] ?? 'test_password',
            'ssl' => false,
        ]);

        $exception = null;
        try {
            await(Connection::create($config));
        } catch (ConnectionException $e) {
            $exception = $e;
        }

        expect($exception)->toBeInstanceOf(ConnectionException::class)
            ->and($exception->getMessage())->toContain('Connections using insecure transport are prohibited');
    });

    it('connects successfully via SSL with verification disabled', function (): void {
        $config = MysqlConfig::fromArray([
            'host' => $_ENV['MYSQL_SSL_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['MYSQL_SSL_PORT'] ?? 3307),
            'database' => $_ENV['MYSQL_SSL_DATABASE'] ?? 'test',
            'username' => $_ENV['MYSQL_SSL_USERNAME'] ?? 'test_user',
            'password' => $_ENV['MYSQL_SSL_PASSWORD'] ?? 'test_password',
            'ssl' => true,
            'ssl_verify' => false,
        ]);

        $conn = await(Connection::create($config));

        $result = await($conn->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'"));
        $cipher = $result->fetchOne()['Value'];

        expect($conn->isReady())->toBeTrue()
            ->and($cipher)->not->toBeEmpty();

        $conn->close();
    });

    it('connects successfully with strict CA verification', function (): void {
        $caPath = realpath(__DIR__ . '/../Fixtures/ssl/ca.pem');

        $config = MysqlConfig::fromArray([
            'host' => $_ENV['MYSQL_SSL_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['MYSQL_SSL_PORT'] ?? 3307),
            'database' => $_ENV['MYSQL_SSL_DATABASE'] ?? 'test',
            'username' => $_ENV['MYSQL_SSL_USERNAME'] ?? 'test_user',
            'password' => $_ENV['MYSQL_SSL_PASSWORD'] ?? 'test_password',
            'ssl' => true,
            'ssl_verify' => true,
            'ssl_ca' => $caPath,
        ]);

        $conn = await(Connection::create($config));

        $result = await($conn->query("SHOW SESSION STATUS LIKE 'Ssl_version'"));
        $tlsVersion = $result->fetchOne()['Value'];

        expect($conn->isReady())->toBeTrue()
            ->and($tlsVersion)->toContain('TLS');

        $conn->close();
    });

    it('fails to connect with invalid CA or Hostname mismatch', function (): void {
        $fakeCaPath = realpath(__DIR__ . '/../Fixtures/ssl/wrong_ca.pem');

        $config = MysqlConfig::fromArray([
            'host' => '127.0.0.1',
            'port' => (int) ($_ENV['MYSQL_SSL_PORT'] ?? 3307),
            'username' => 'test_user',
            'ssl' => true,
            'ssl_verify' => true,
            'ssl_ca' => $fakeCaPath,
        ]);

        $exception = null;
        try {
            await(Connection::create($config));
        } catch (ConnectionException $e) {
            $exception = $e;
        }

        expect($exception)->toBeInstanceOf(ConnectionException::class)
            ->and($exception->getMessage())->toMatch('/verify_peer|certificate/i');
    });

    it('connects successfully using Client Certificates (mTLS)', function (): void {
        $fixtureDir = __DIR__ . '/../Fixtures/ssl';

        $caPath   = realpath($fixtureDir . '/ca.pem');
        $certPath = realpath($fixtureDir . '/client-cert.pem');
        $keyPath  = realpath($fixtureDir . '/client-key.pem');

        if ($caPath === false || $certPath === false || $keyPath === false) {
            $this->fail(sprintf(
                "Missing SSL fixtures in %s.\nFound:\nCA: %s\nCert: %s\nKey: %s",
                realpath($fixtureDir),
                $caPath ? 'OK' : 'MISSING',
                $certPath ? 'OK' : 'MISSING',
                $keyPath ? 'OK' : 'MISSING'
            ));
        }

        $config = MysqlConfig::fromArray([
            'host' => $_ENV['MYSQL_SSL_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['MYSQL_SSL_PORT'] ?? 3307),
            'database' => $_ENV['MYSQL_SSL_DATABASE'] ?? 'test',
            'username' => $_ENV['MYSQL_SSL_USERNAME'] ?? 'test_user',
            'password' => $_ENV['MYSQL_SSL_PASSWORD'] ?? 'test_password',
            'ssl' => true,
            'ssl_verify' => true,
            'ssl_ca' => $caPath,
            'ssl_cert' => $certPath,
            'ssl_key' => $keyPath,
        ]);

        $conn = await(Connection::create($config));

        expect($conn->isReady())->toBeTrue();
        $conn->close();
    });

    it('handles large packets (>16MB) over SSL', function (): void {

        $fixtureDir = __DIR__ . '/../Fixtures/ssl';

        $caPath   = realpath($fixtureDir . '/ca.pem');
        $certPath = realpath($fixtureDir . '/client-cert.pem');
        $keyPath  = realpath($fixtureDir . '/client-key.pem');
        
        $config = MysqlConfig::fromArray([
            'host' => $_ENV['MYSQL_SSL_HOST'] ?? '127.0.0.1',
            'port' => (int) ($_ENV['MYSQL_SSL_PORT'] ?? 3307),
            'database' => $_ENV['MYSQL_SSL_DATABASE'] ?? 'test',
            'username' => $_ENV['MYSQL_SSL_USERNAME'] ?? 'test_user',
            'password' => $_ENV['MYSQL_SSL_PASSWORD'] ?? 'test_password',
            'ssl' => true,
            'ssl_verify' => true,
            'ssl_ca' => $caPath,
            'ssl_cert' => $certPath,
            'ssl_key' => $keyPath,
        ]);

        $conn = await(Connection::create($config));

        await($conn->query("CREATE TEMPORARY TABLE IF NOT EXISTS test_large_packet (data LONGBLOB)"));

        $size = 17 * 1024 * 1024;

        $largeString = str_repeat('A', $size);

        $stmt = await($conn->prepare("INSERT INTO test_large_packet (data) VALUES (?)"));
        await($stmt->execute([$largeString]));

        $result = await($conn->query("SELECT data FROM test_large_packet"));
        $row = $result->fetchOne();

        expect(strlen($row['data']))->toBe($size)
            ->and(md5($row['data']))->toBe(md5($largeString));

        $conn->close();
    });
})->skipOnWindows();
