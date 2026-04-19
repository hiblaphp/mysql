<?php

declare(strict_types=1);

namespace Hibla\Mysql\Tests\Internals;

use Hibla\Mysql\Internals\Connection;
use Hibla\Mysql\Internals\PreparedStatement;
use Hibla\Mysql\ValueObjects\StreamContext;
use Hibla\Promise\Promise;
use Hibla\Sql\Exceptions\PreparedException;
use InvalidArgumentException;
use Mockery;

describe('PreparedStatement', function (): void {

    it('validates parameter count on execute', function (): void {
        $conn = Mockery::mock(Connection::class);

        $conn->shouldReceive('closeStatement')->andReturn(Promise::resolved())->byDefault();

        $stmt = new PreparedStatement($conn, id: 1, numColumns: 0, numParams: 2);

        expect(fn () => $stmt->execute([1]))
            ->toThrow(InvalidArgumentException::class, 'Statement expects 2 parameters, got 1')
        ;
    });

    it('validates parameter count on executeStream', function (): void {
        $conn = Mockery::mock(Connection::class);
        $conn->shouldReceive('closeStatement')->andReturn(Promise::resolved())->byDefault();

        $stmt = new PreparedStatement($conn, id: 1, numColumns: 0, numParams: 1);

        expect(fn () => $stmt->executeStream([]))
            ->toThrow(InvalidArgumentException::class, 'Statement expects 1 parameters, got 0')
        ;
    });

    it('normalizes boolean parameters to integers before executing', function (): void {
        $conn = Mockery::mock(Connection::class);
        $conn->shouldReceive('closeStatement')->andReturn(Promise::resolved())->byDefault();

        $stmt = new PreparedStatement($conn, id: 1, numColumns: 0, numParams: 3);

        $promise = new Promise();

        $conn->shouldReceive('executeStatement')
             ->once()
             ->with($stmt, [1, 0, 'test'])
             ->andReturn($promise)
        ;

        $result = $stmt->execute([true, false, 'test']);
        expect($result)->toBe($promise);
    });

    it('delegates executeStream to the connection with a StreamContext', function (): void {
        $conn = Mockery::mock(Connection::class);
        $conn->shouldReceive('closeStatement')->andReturn(Promise::resolved())->byDefault();

        $stmt = new PreparedStatement($conn, id: 1, numColumns: 0, numParams: 1);

        $promise = new Promise();

        $conn->shouldReceive('executeStream')
             ->once()
             ->with($stmt, ['value'], Mockery::type(StreamContext::class))
             ->andReturn($promise)
        ;

        $stmt->executeStream(['value']);
        expect(true)->toBeTrue();
    });

    it('sends close packet to connection when close() is called', function (): void {
        $conn = Mockery::mock(Connection::class);
        $stmt = new PreparedStatement($conn, id: 123, numColumns: 0, numParams: 0);

        $promise = new Promise();

        $conn->shouldReceive('closeStatement')
             ->once()
             ->with(123)
             ->andReturn($promise)
        ;

        $result = $stmt->close();
        expect($result)->toBe($promise);
    });

    it('prevents execution after being closed', function (): void {
        $conn = Mockery::mock(Connection::class);
        $conn->shouldReceive('closeStatement')->once()->andReturn(Promise::resolved());

        $stmt = new PreparedStatement($conn, id: 1, numColumns: 0, numParams: 0);
        $stmt->close();

        expect(fn () => $stmt->execute([]))
            ->toThrow(PreparedException::class, 'Cannot execute a closed statement')
        ;

        expect(fn () => $stmt->executeStream([]))
            ->toThrow(PreparedException::class, 'Cannot execute a closed statement')
        ;
    });

    it('automatically closes on destruction', function (): void {
        $conn = Mockery::mock(Connection::class);

        $conn->shouldReceive('closeStatement')
             ->once()
             ->with(456)
             ->andReturn(Promise::resolved())
        ;

        $stmt = new PreparedStatement($conn, id: 456, numColumns: 0, numParams: 0);

        unset($stmt);

        expect(true)->toBeTrue();
    });
});
