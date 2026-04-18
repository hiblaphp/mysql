<?php

declare(strict_types=1);

namespace Hibla\Mysql\Tests\Internals;

use Hibla\Mysql\Internals\Connection;
use Hibla\Mysql\Internals\ConnectionSetup;
use Hibla\Mysql\Internals\Result;
use Hibla\Promise\Promise;
use Mockery;

use function Hibla\await;

describe('ConnectionSetup', function (): void {

    it('delegates query() to the underlying connection and returns its promise', function (): void {
        $conn     = Mockery::mock(Connection::class);
        $expected = new Promise();

        $conn->shouldReceive('query')->once()->with('SELECT 1')->andReturn($expected);

        expect((new ConnectionSetup($conn))->query('SELECT 1'))->toBe($expected);
    });

    it('execute() resolves with the affected row count from the result', function (): void {
        $conn = Mockery::mock(Connection::class);
        $conn->shouldReceive('query')
             ->once()
             ->with('DELETE FROM logs')
             ->andReturn(Promise::resolved(new Result(affectedRows: 7)))
        ;

        // then() is a microtask even for already-settled promises — use await()
        $resolved = await((new ConnectionSetup($conn))->execute('DELETE FROM logs'));

        expect($resolved)->toBe(7);
    });

    it('execute() resolves with 0 when no rows were affected', function (): void {
        $conn = Mockery::mock(Connection::class);
        $conn->shouldReceive('query')->once()->andReturn(Promise::resolved(new Result(affectedRows: 0)));

        $resolved = await((new ConnectionSetup($conn))->execute('SELECT 1'));

        expect($resolved)->toBe(0);
    });
});