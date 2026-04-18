<?php

declare(strict_types=1);

namespace Hibla\Mysql\Tests\Internals;

use Hibla\Mysql\Internals\Connection;
use Hibla\Mysql\Internals\TransactionPreparedStatement;
use Hibla\Promise\Promise;
use Hibla\Sql\PreparedStatement as PreparedStatementInterface;
use Mockery;

describe('TransactionPreparedStatement', function (): void {

    it('delegates execute() to the underlying statement', function (): void {
        $inner = Mockery::mock(PreparedStatementInterface::class);
        $conn  = Mockery::mock(Connection::class);

        $conn->shouldReceive('isClosed')->andReturn(false)->byDefault();
        $inner->shouldReceive('close')->andReturn(Promise::resolved())->byDefault();

        $expected = new Promise();
        $inner->shouldReceive('execute')->once()->with([42])->andReturn($expected);

        expect(new TransactionPreparedStatement($inner, $conn))->execute([42])->toBe($expected);
    });

    it('delegates executeStream() to the underlying statement', function (): void {
        $inner = Mockery::mock(PreparedStatementInterface::class);
        $conn  = Mockery::mock(Connection::class);

        $conn->shouldReceive('isClosed')->andReturn(false)->byDefault();
        $inner->shouldReceive('close')->andReturn(Promise::resolved())->byDefault();

        $expected = new Promise();
        $inner->shouldReceive('executeStream')->once()->with(['val'])->andReturn($expected);

        expect(new TransactionPreparedStatement($inner, $conn))->executeStream(['val'])->toBe($expected);
    });

    it('close() forwards the close command to the underlying statement', function (): void {
        $inner = Mockery::mock(PreparedStatementInterface::class);
        $conn  = Mockery::mock(Connection::class);

        $conn->shouldReceive('isClosed')->andReturn(false)->byDefault();

        $promise = new Promise();
        $inner->shouldReceive('close')->once()->andReturn($promise);

        expect(new TransactionPreparedStatement($inner, $conn))->close()->toBe($promise);
    });

    it('subsequent close() calls are no-ops that return a resolved promise', function (): void {
        $inner = Mockery::mock(PreparedStatementInterface::class);
        $conn  = Mockery::mock(Connection::class);

        $conn->shouldReceive('isClosed')->andReturn(false)->byDefault();
        $inner->shouldReceive('close')->once()->andReturn(Promise::resolved()); 

        $stmt = new TransactionPreparedStatement($inner, $conn);
        $stmt->close();

        $second = $stmt->close(); 

        expect($second)->not->toBeNull();
    });

    it('__destruct closes the statement when it has not been closed yet', function (): void {
        $inner = Mockery::mock(PreparedStatementInterface::class);
        $conn  = Mockery::mock(Connection::class);

        $conn->shouldReceive('isClosed')->andReturn(false);
        $inner->shouldReceive('close')->once()->andReturn(Promise::resolved());

        $stmt = new TransactionPreparedStatement($inner, $conn);
        unset($stmt);

        expect(true)->toBeTrue();
    });

    it('__destruct skips closing the statement when the connection is already closed', function (): void {
        $inner = Mockery::mock(PreparedStatementInterface::class);
        $conn  = Mockery::mock(Connection::class);

        $conn->shouldReceive('isClosed')->andReturn(true);
        $inner->shouldNotReceive('close');

        $stmt = new TransactionPreparedStatement($inner, $conn);
        unset($stmt);

        expect(true)->toBeTrue();
    });
});