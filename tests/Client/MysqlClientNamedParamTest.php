<?php

declare(strict_types=1);

use Hibla\Sql\Exceptions\QueryException;
use Hibla\Sql\Transaction;

use function Hibla\await;

beforeAll(function (): void {
    $client = makeClient();
    await($client->query('CREATE TABLE IF NOT EXISTS client_named_test (
        id INT PRIMARY KEY AUTO_INCREMENT, 
        role VARCHAR(50), 
        active TINYINT(1)
    )'));
    await($client->query("INSERT INTO client_named_test (role, active) VALUES ('admin', 1), ('user', 1), ('user', 0)"));
    $client->close();
});

afterAll(function (): void {
    $client = makeClient();
    await($client->query('DROP TABLE IF EXISTS client_named_test'));
    $client->close();
});

describe('MysqlClient Named Parameters', function (): void {

    it('works with query()', function (): void {
        $client = makeClient();
        $result = await($client->query('SELECT * FROM client_named_test WHERE role = :role AND active = :active', [
            'role' => 'admin',
            'active' => 1
        ]));

        expect($result->rowCount)->toBe(1)
            ->and($result->fetchOne()['role'])->toBe('admin');

        $client->close();
    });

    it('works with fetchOne()', function (): void {
        $client = makeClient();
        $row = await($client->fetchOne('SELECT * FROM client_named_test WHERE role = :role ORDER BY id ASC', [
            'role' => 'user'
        ]));

        expect($row['active'])->toBe(1);

        $client->close();
    });

    it('works with fetchValue()', function (): void {
        $client = makeClient();
        $count = await($client->fetchValue('SELECT COUNT(*) as c FROM client_named_test WHERE active = :state', 'c', [
            'state' => 1
        ]));

        expect((int)$count)->toBe(2);

        $client->close();
    });

    it('works with execute() and executeGetId()', function (): void {
        $client = makeClient();

        $insertId = await($client->executeGetId('INSERT INTO client_named_test (role, active) VALUES (:role, :active)', [
            'role' => 'guest',
            'active' => 0
        ]));

        expect($insertId)->toBeGreaterThan(3);

        $affected = await($client->execute('DELETE FROM client_named_test WHERE id = :id', [
            'id' => $insertId
        ]));

        expect($affected)->toBe(1);

        $client->close();
    });

    it('works with stream()', function (): void {
        $client = makeClient();
        $stream = await($client->stream('SELECT id FROM client_named_test WHERE active = :active ORDER BY id', [
            'active' => 1
        ]));

        $ids = [];
        foreach ($stream as $row) {
            $ids[] = $row['id'];
        }

        expect($ids)->toHaveCount(2)
            ->and($ids)->toBe([1, 2]);

        $client->close();
    });

    it('works inside transactions', function (): void {
        $client = makeClient();

        await($client->transaction(function (Transaction $tx) {
            $res = await($tx->query('SELECT * FROM client_named_test WHERE id = :id', ['id' => 1]));
            expect($res->rowCount)->toBe(1);

            $affected = await($tx->execute('UPDATE client_named_test SET active = :active WHERE role = :role', [
                'active' => 0,
                'role' => 'admin'
            ]));
            expect($affected)->toBe(1);

            throw new \RuntimeException('Trigger rollback');
        })->catch(fn() => null));

        $row = await($client->fetchOne('SELECT active FROM client_named_test WHERE id = 1'));
        expect((int)$row['active'])->toBe(1);

        $client->close();
    });

    it('caches prepared statements correctly when using named parameters', function (): void {
        $client = makeClient(enableStatementCache: true);

        $row1 = await($client->fetchOne('SELECT role FROM client_named_test WHERE id = :id', ['id' => 1]));
        expect($row1['role'])->toBe('admin');

        $row2 = await($client->fetchOne('SELECT role FROM client_named_test WHERE id = :id', ['id' => 2]));
        expect($row2['role'])->toBe('user');

        $client->close();
    });

    it('throws QueryException on missing parameters at the Client level', function (): void {
        $client = makeClient();

        expect(
            fn() => await(
                $client->query('SELECT * FROM client_named_test WHERE role = :role', [])
            )
        )->toThrow(QueryException::class);

        $client->close();
    });
});
