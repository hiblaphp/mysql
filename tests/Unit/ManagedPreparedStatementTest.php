<?php

declare(strict_types=1);

namespace Hibla\Mysql\Tests\Internals;

use Hibla\Mysql\Internals\Connection;
use Hibla\Mysql\Internals\ManagedPreparedStatement;
use Hibla\Mysql\Manager\PoolManager;
use Hibla\Promise\Promise;
use Hibla\Sql\PreparedStatement as PreparedStatementInterface;
use Mockery;

use function Hibla\await;

describe('ManagedPreparedStatement', function (): void {

    it('delegates execute() to the underlying statement', function (): void {
        $inner = Mockery::mock(PreparedStatementInterface::class);
        $conn  = Mockery::mock(Connection::class);
        $pool  = Mockery::mock(PoolManager::class);

        $conn->shouldReceive('isClosed')->andReturn(false)->byDefault();
        $conn->shouldReceive('close')->byDefault();
        $pool->shouldReceive('release')->byDefault();
        $inner->shouldReceive('close')->andReturn(Promise::resolved())->byDefault();

        $expected = new Promise();
        $inner->shouldReceive('execute')->once()->with([1, 'a'])->andReturn($expected);

        $managed = new ManagedPreparedStatement($inner, $conn, $pool);

        expect($managed->execute([1, 'a']))->toBe($expected);
    });

    it('delegates executeStream() to the underlying statement', function (): void {
        $inner = Mockery::mock(PreparedStatementInterface::class);
        $conn  = Mockery::mock(Connection::class);
        $pool  = Mockery::mock(PoolManager::class);

        $conn->shouldReceive('isClosed')->andReturn(false)->byDefault();
        $conn->shouldReceive('close')->byDefault();
        $pool->shouldReceive('release')->byDefault();
        $inner->shouldReceive('close')->andReturn(Promise::resolved())->byDefault();

        $expected = new Promise();
        $inner->shouldReceive('executeStream')->once()->with([])->andReturn($expected);

        $managed = new ManagedPreparedStatement($inner, $conn, $pool);

        expect($managed->executeStream([]))->toBe($expected);
    });

    it('releases the connection back to the pool after close() settles', function (): void {
        $inner = Mockery::mock(PreparedStatementInterface::class);
        $conn  = Mockery::mock(Connection::class);
        $pool  = Mockery::mock(PoolManager::class);

        $conn->shouldReceive('isClosed')->andReturn(true)->byDefault();
        $inner->shouldReceive('close')->once()->andReturn(Promise::resolved());
    
        $pool->shouldReceive('release')->once()->with($conn);

        $managed = new ManagedPreparedStatement($inner, $conn, $pool);
        await($managed->close());

        expect(true)->toBeTrue();
    });

    it('does not release the connection twice when close() is followed by destruction', function (): void {
        $inner = Mockery::mock(PreparedStatementInterface::class);
        $conn  = Mockery::mock(Connection::class);
        $pool  = Mockery::mock(PoolManager::class);

        $conn->shouldReceive('isClosed')->andReturn(true)->byDefault();
        $inner->shouldReceive('close')->once()->andReturn(Promise::resolved());
        
        $pool->shouldReceive('release')->once()->with($conn);

        $managed = new ManagedPreparedStatement($inner, $conn, $pool);

        await($managed->close());
        unset($managed); 

        expect(true)->toBeTrue();
    });

    it('closes the underlying connection and releases the pool on destruction when not yet released', function (): void {
        $inner = Mockery::mock(PreparedStatementInterface::class);
        $conn  = Mockery::mock(Connection::class);
        $pool  = Mockery::mock(PoolManager::class);

        $conn->shouldReceive('isClosed')->andReturn(false);
        $conn->shouldReceive('close')->once();
        $pool->shouldReceive('release')->once()->with($conn);
        $inner->shouldReceive('close')->andReturn(Promise::resolved())->byDefault();

        $managed = new ManagedPreparedStatement($inner, $conn, $pool);
        unset($managed);

        expect(true)->toBeTrue();
    });

    it('skips closing the connection on destruction when it is already closed', function (): void {
        $inner = Mockery::mock(PreparedStatementInterface::class);
        $conn  = Mockery::mock(Connection::class);
        $pool  = Mockery::mock(PoolManager::class);

        $conn->shouldReceive('isClosed')->andReturn(true);
        $conn->shouldNotReceive('close');
        $pool->shouldReceive('release')->once();
        $inner->shouldReceive('close')->andReturn(Promise::resolved())->byDefault();

        $managed = new ManagedPreparedStatement($inner, $conn, $pool);
        unset($managed);

        expect(true)->toBeTrue();
    });
});