<?php

declare(strict_types=1);

use Hibla\Mysql\Internals\Connection;
use Hibla\Mysql\MysqlClient;
use Hibla\Mysql\ValueObjects\MysqlConfig;

use function Hibla\await;

describe('CastPreparedTypes Feature', function (): void {

    describe('Connection Level', function (): void {

        it('returns native PHP types by default (castPreparedTypes = true)', function (): void {
            $conn = makeConnection();

            $stmt = await($conn->prepare('SELECT ? AS i, ? AS f, ? AS s'));
            $result = await($stmt->execute([42, 3.14, 'hello']));
            $row = $result->fetchOne();

            expect($row['i'])->toBe(42)->and($row['i'])->toBeInt()
                ->and($row['f'])->toBe(3.14)->and($row['f'])->toBeFloat()
                ->and($row['s'])->toBe('hello')->and($row['s'])->toBeString()
            ;

            await($stmt->close());
            $conn->close();
        });

        it('returns string types when castPreparedTypes is set to false', function (): void {
            $config = testMysqlConfig();

            $customConfig = new MysqlConfig(
                host: $config->host,
                port: $config->port,
                username: $config->username,
                password: $config->password,
                database: $config->database,
                castPreparedTypes: false
            );

            $conn = await(Connection::create($customConfig));

            $stmt = await($conn->prepare('SELECT ? AS i, ? AS f, ? AS s'));
            $result = await($stmt->execute([42, 3.14, 'hello']));
            $row = $result->fetchOne();

            expect($row['i'])->toBe('42')->and($row['i'])->toBeString()
                ->and($row['f'])->toBe('3.14')->and($row['f'])->toBeString()
                ->and($row['s'])->toBe('hello')->and($row['s'])->toBeString()
            ;

            await($stmt->close());
            $conn->close();
        });

        it('preserves NULL values even when stringification is active', function (): void {
            $config = testMysqlConfig();

            $customConfig = new MysqlConfig(
                host: $config->host,
                port: $config->port,
                username: $config->username,
                password: $config->password,
                database: $config->database,
                castPreparedTypes: false
            );

            $conn = await(Connection::create($customConfig));

            $stmt = await($conn->prepare('SELECT ? AS n'));
            $result = await($stmt->execute([null]));
            $row = $result->fetchOne();

            expect($row['n'])->toBeNull();

            await($stmt->close());
            $conn->close();
        });
    });

    describe('MysqlClient Level', function (): void {

        it('propagates castPreparedTypes config through the client to query results', function (): void {
            $client = new MysqlClient(
                config: testMysqlConfig(),
                castPreparedTypes: false
            );

            $result = await($client->query('SELECT ? AS val', [100]));
            $row = $result->fetchOne();

            expect($row['val'])->toBe('100')->and($row['val'])->toBeString();

            $client->close();
        });

        it('propagates castPreparedTypes config through the client to streaming results', function (): void {
            $client = new MysqlClient(
                config: testMysqlConfig(),
                castPreparedTypes: false
            );

            $stream = await($client->stream('SELECT ? AS val', [500]));

            $rows = [];
            foreach ($stream as $row) {
                $rows[] = $row;
            }

            expect($rows[0]['val'])->toBe('500')->and($rows[0]['val'])->toBeString();

            $client->close();
        });

        it('correctly handles native types by default in the client', function (): void {
            $client = new MysqlClient(testMysqlConfig());

            $result = await($client->query('SELECT ? AS val', [100]));
            $row = $result->fetchOne();

            expect($row['val'])->toBe(100)->and($row['val'])->toBeInt();

            $client->close();
        });
    });
});
