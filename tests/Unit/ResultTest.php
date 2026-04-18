<?php

declare(strict_types=1);

namespace Hibla\Mysql\Tests\Internals;

use Hibla\Mysql\Internals\Result;
use Hibla\Mysql\ValueObjects\MysqlColumnDefinition;

describe('Result', function (): void {
    it('initializes correctly with provided data', function (): void {
        $cols = [createMockColumn('id'), createMockColumn('name')];
        $rows = [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']];

        $result = new Result(
            affectedRows: 5,
            lastInsertId: 100,
            warningCount: 2,
            connectionId: 42,
            columnDefinitions: $cols,
            rows: $rows
        );

        expect($result->affectedRows)->toBe(5)
            ->and($result->lastInsertId)->toBe(100)
            ->and($result->warningCount)->toBe(2)
            ->and($result->connectionId)->toBe(42)
            ->and($result->rowCount)->toBe(2)
            ->and($result->columnCount)->toBe(2)
            ->and($result->fields[0])->toBeInstanceOf(MysqlColumnDefinition::class)
            ->and($result->columns)->toBe(['id', 'name'])
        ;
    });

    it('correctly reports presence of affected rows and last insert ID', function (): void {
        $emptyResult = new Result();
        expect($emptyResult->hasAffectedRows())->toBeFalse()
            ->and($emptyResult->hasLastInsertId())->toBeFalse()
        ;

        $activeResult = new Result(affectedRows: 1, lastInsertId: 10);
        expect($activeResult->hasAffectedRows())->toBeTrue()
            ->and($activeResult->hasLastInsertId())->toBeTrue()
        ;
    });

    it('correctly reports if result set is empty', function (): void {
        $empty = new Result(rows: []);
        expect($empty->isEmpty())->toBeTrue();

        $filled = new Result(rows: [['id' => 1]]);
        expect($filled->isEmpty())->toBeFalse();
    });

    it('fetches associative rows sequentially and returns null when done', function (): void {
        $rows = [['id' => 1], ['id' => 2]];
        $result = new Result(rows: $rows);

        expect($result->fetchAssoc())->toBe(['id' => 1])
            ->and($result->fetchAssoc())->toBe(['id' => 2])
            ->and($result->fetchAssoc())->toBeNull()
        ;
    });

    it('fetches all rows at once', function (): void {
        $rows = [['id' => 1], ['id' => 2]];
        $result = new Result(rows: $rows);

        expect($result->fetchAll())->toBe($rows);
    });

    it('fetches the first row via fetchOne', function (): void {
        $rows = [['id' => 1], ['id' => 2]];
        $result = new Result(rows: $rows);

        expect($result->fetchOne())->toBe(['id' => 1]);
    });

    it('returns null for fetchOne when empty', function (): void {
        $result = new Result(rows: []);
        expect($result->fetchOne())->toBeNull();
    });

    it('fetches a specific column by name or index across all rows', function (): void {
        $rows = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];
        $result = new Result(rows: $rows);

        expect($result->fetchColumn('name'))->toBe(['Alice', 'Bob'])
            ->and($result->fetchColumn('id'))->toBe([1, 2])
            ->and($result->fetchColumn('nonexistent'))->toBe([null, null])
        ;
    });

    it('supports iteration via foreach', function (): void {
        $rows = [['id' => 1], ['id' => 2]];
        $result = new Result(rows: $rows);

        $iterated = [];
        foreach ($result as $row) {
            $iterated[] = $row;
        }

        expect($iterated)->toBe($rows);
    });

    it('links and traverses multiple result sets', function (): void {
        $result1 = new Result(rows: [['id' => 1]]);
        $result2 = new Result(rows: [['id' => 2]]);
        
        $result1->setNextResult($result2);

        expect($result1->nextResult())->toBe($result2)
            ->and($result2->nextResult())->toBeNull()
        ;
    });
});