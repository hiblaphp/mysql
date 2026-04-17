<?php

declare(strict_types=1);

use Hibla\Sql\Exceptions\PreparedException;
use Hibla\Sql\Exceptions\QueryException;

use function Hibla\await;

beforeAll(function (): void {
    $client = makeClient();
    await($client->query('DROP TABLE IF EXISTS multi_stmt_test'));
    await($client->query('CREATE TABLE multi_stmt_test (id INT AUTO_INCREMENT PRIMARY KEY, val VARCHAR(50))'));
    $client->close();
});

afterAll(function (): void {
    $client = makeClient();
    await($client->query('DROP TABLE IF EXISTS multi_stmt_test'));
    $client->close();
});

describe('Multi-Statements (Stacked Queries)', function (): void {

    it('throws an error by default when multi-statements are executed (Security check)', function (): void {
        $client = makeClient();

        expect(fn () => await($client->query('SELECT 1; SELECT 2;')))
            ->toThrow(QueryException::class)
        ;

        $client->close();
    });

    it('successfully executes and traverses stacked queries when explicitly enabled', function (): void {
        $client = makeClient(enableMultiStatements: true);

        $result = await($client->query('SELECT 10 AS a; SELECT 20 AS b; SELECT 30 AS c;'));

        expect((int)$result->fetchOne()['a'])->toBe(10);

        $result = $result->nextResult();
        expect($result)->not->toBeNull();
        expect((int)$result->fetchOne()['b'])->toBe(20);

        $result = $result->nextResult();
        expect($result)->not->toBeNull();
        expect((int)$result->fetchOne()['c'])->toBe(30);

        expect($result->nextResult())->toBeNull();

        $client->close();
    });

    it('handles mixed DML and SELECTs in a single stacked string', function (): void {
        $client = makeClient(enableMultiStatements: true);

        $sql = "
            INSERT INTO multi_stmt_test (val) VALUES ('stack1');
            INSERT INTO multi_stmt_test (val) VALUES ('stack2');
            SELECT COUNT(*) AS c FROM multi_stmt_test WHERE val LIKE 'stack%';
            UPDATE multi_stmt_test SET val = 'updated' WHERE val = 'stack1';
        ";

        $result = await($client->query($sql));

        expect($result->affectedRows)->toBe(1);

        $result = $result->nextResult();
        expect($result->affectedRows)->toBe(1);

        $result = $result->nextResult();
        expect((int)$result->fetchOne()['c'])->toBeGreaterThanOrEqual(2);

        $result = $result->nextResult();
        expect($result->affectedRows)->toBe(1);

        $client->close();
    });

    it('throws PreparedException when attempting multi-statements via Binary Protocol', function (): void {
        $client = makeClient(enableMultiStatements: true);

        expect(fn () => await($client->query('SELECT ?; SELECT ?;', [1, 2])))
            ->toThrow(PreparedException::class)
        ;

        $client->close();
    });

    it('handles syntax errors mid-stack without breaking the connection pool', function (): void {
        $client = makeClient(enableMultiStatements: true);

        $sql = 'SELECT 1; SELEECT 2;';

        expect(fn () => await($client->query($sql)))
            ->toThrow(QueryException::class)
        ;

        $result = await($client->query('SELECT "healthy" AS status'));
        expect($result->fetchOne()['status'])->toBe('healthy');

        $client->close();
    });

    it('traverses gracefully over stacked empty result sets', function (): void {
        $client = makeClient(enableMultiStatements: true);

        $sql = '
            SELECT 1 AS val WHERE 1=0; 
            SELECT 2 AS val; 
            SELECT 3 AS val WHERE 1=0;
        ';

        $result = await($client->query($sql));

        expect($result->rowCount)->toBe(0);

        $result = $result->nextResult();
        expect($result)->not->toBeNull()
            ->and((int)$result->fetchOne()['val'])->toBe(2)
        ;

        $result = $result->nextResult();
        expect($result)->not->toBeNull()
            ->and($result->rowCount)->toBe(0)
        ;

        expect($result->nextResult())->toBeNull();

        $client->close();
    });
});
