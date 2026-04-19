<?php

declare(strict_types=1);

namespace Hibla\Mysql\Tests\Internals;

use Hibla\Mysql\Internals\Result;
use Hibla\Mysql\Internals\Transaction;
use Hibla\Promise\Promise;
use Hibla\Sql\Exceptions\TransactionException;

use function Hibla\await;
use function Hibla\delay;

describe('Transaction', function (): void {

    it('is active immediately after creation', function (): void {
        [$conn, $pool] = makeTxMocks();
        $tx = new Transaction($conn, $pool);

        expect($tx->isActive())->toBeTrue()
            ->and($tx->isClosed())->toBeFalse()
        ;
    });

    it('isActive() returns false once commit() completes', function (): void {
        [$conn, $pool] = makeTxMocks();
        $conn->shouldReceive('query')->with('COMMIT')->once()->andReturn(Promise::resolved(new Result()));

        $tx = new Transaction($conn, $pool);
        await($tx->commit());

        expect($tx->isActive())->toBeFalse();
    });

    it('isActive() returns false once rollback() completes', function (): void {
        [$conn, $pool] = makeTxMocks();
        $conn->shouldReceive('query')->with('ROLLBACK')->once()->andReturn(Promise::resolved(new Result()));

        $tx = new Transaction($conn, $pool);
        await($tx->rollback());

        expect($tx->isActive())->toBeFalse();
    });

    it('commit() releases the connection back to the pool', function (): void {
        [$conn, $pool] = makeTxMocks();
        $conn->shouldReceive('query')->with('COMMIT')->once()->andReturn(Promise::resolved(new Result()));
        $pool->shouldReceive('release')->once()->with($conn);

        $tx = new Transaction($conn, $pool);
        await($tx->commit());

        expect($tx->isActive())->toBeFalse();
    });

    it('commit() invokes onCommit callbacks and clears onRollback callbacks', function (): void {
        [$conn, $pool] = makeTxMocks();
        $conn->shouldReceive('query')->with('COMMIT')->once()->andReturn(Promise::resolved(new Result()));

        $called = false;
        $tx = new Transaction($conn, $pool);
        $tx->onCommit(function () use (&$called): void {
            $called = true;
        });
        $tx->onRollback(fn () => null);

        await($tx->commit());

        expect($called)->toBeTrue();
    });

    it('commit() rejects when the transaction is tainted by a prior query failure', function (): void {
        [$conn, $pool] = makeTxMocks();
        $conn->shouldReceive('query')
             ->with('SELECT fail')
             ->andReturn(Promise::rejected(new \RuntimeException('DB error')))
        ;

        $tx = new Transaction($conn, $pool);

        try {
            await($tx->query('SELECT fail'));
        } catch (\Throwable) {
        }

        expect(fn () => await($tx->commit()))->toThrow(TransactionException::class);
    });

    it('rollback() releases the connection back to the pool', function (): void {
        [$conn, $pool] = makeTxMocks();

        $conn->shouldReceive('query')->with('ROLLBACK')->once()->andReturn(Promise::resolved(new Result()));
        $pool->shouldReceive('release')->once()->with($conn);

        $tx = new Transaction($conn, $pool);
        await($tx->rollback());

        expect($tx->isActive())->toBeFalse();
    });

    it('rollback() invokes onRollback callbacks and clears onCommit callbacks', function (): void {
        [$conn, $pool] = makeTxMocks();
        $conn->shouldReceive('query')->with('ROLLBACK')->once()->andReturn(Promise::resolved(new Result()));

        $called = false;
        $tx = new Transaction($conn, $pool);
        $tx->onRollback(function () use (&$called): void {
            $called = true;
        });
        $tx->onCommit(fn () => null);

        await($tx->rollback());

        expect($called)->toBeTrue();
    });

    it('query() throws TransactionException when the transaction is no longer active', function (): void {
        [$conn, $pool] = makeTxMocks();
        $conn->shouldReceive('query')->with('COMMIT')->once()->andReturn(Promise::resolved(new Result()));

        $tx = new Transaction($conn, $pool);
        await($tx->commit());

        expect(fn () => $tx->query('SELECT 1'))->toThrow(TransactionException::class);
    });

    it('query() marks the transaction as tainted after a query rejection', function (): void {
        [$conn, $pool] = makeTxMocks();
        $conn->shouldReceive('query')
             ->with('SELECT fail')
             ->andReturn(Promise::rejected(new \RuntimeException('err')))
        ;

        $tx = new Transaction($conn, $pool);

        try {
            await($tx->query('SELECT fail'));
        } catch (\Throwable) {
        }

        expect(fn () => $tx->query('SELECT 1'))
            ->toThrow(TransactionException::class, 'Transaction aborted due to a previous query error')
        ;
    });

    it('execute() resolves with affected row count', function (): void {
        [$conn, $pool] = makeTxMocks();

        $conn->shouldReceive('query')
            ->with('DELETE FROM t')
            ->once()
            ->andReturn(Promise::resolved(new Result(affectedRows: 4)))
        ;

        $tx = new Transaction($conn, $pool);
        $resolved = await($tx->execute('DELETE FROM t'));

        expect($resolved)->toBe(4);

        await($tx->rollback());
    });

    it('executeGetId() resolves with last insert ID', function (): void {
        [$conn, $pool] = makeTxMocks();
        $conn->shouldReceive('query')
            ->with('INSERT INTO t VALUES ()')
            ->once()
            ->andReturn(Promise::resolved(new Result(lastInsertId: 99)))
        ;

        $tx = new Transaction($conn, $pool);
        $resolved = await($tx->executeGetId('INSERT INTO t VALUES ()'));

        expect($resolved)->toBe(99);

        await($tx->rollback());
    });

    it('savepoint() throws for an empty identifier', function (): void {
        [$conn, $pool] = makeTxMocks();
        $tx = new Transaction($conn, $pool);

        expect(fn () => $tx->savepoint(''))->toThrow(\InvalidArgumentException::class);
    });

    it('savepoint() escapes backticks in identifiers correctly', function (): void {
        [$conn, $pool] = makeTxMocks();

        $conn->shouldReceive('query')
             ->with('SAVEPOINT `my``sp`')
             ->once()
             ->andReturn(Promise::resolved(new Result()))
        ;

        $tx = new Transaction($conn, $pool);
        await($tx->savepoint('my`sp'));

        await($tx->rollback());

        expect(true)->toBeTrue();
    });

    it('rollbackTo() clears the failed state so further queries are allowed', function (): void {
        [$conn, $pool] = makeTxMocks();
        $conn->shouldReceive('query')
             ->with('SELECT fail')
             ->andReturn(Promise::rejected(new \RuntimeException('err')))
        ;

        $conn->shouldReceive('query')
             ->with('ROLLBACK TO SAVEPOINT `sp`')
             ->once()
             ->andReturn(Promise::resolved(new Result()))
        ;

        $tx = new Transaction($conn, $pool);

        try {
            await($tx->query('SELECT fail'));
        } catch (\Throwable) {
        }

        await($tx->rollbackTo('sp'));

        expect($tx->isActive())->toBeTrue();
    });

    it('__destruct issues an async ROLLBACK and releases the pool when still active', function (): void {
        [$conn, $pool] = makeTxMocks();

        $conn->shouldReceive('query')->with('ROLLBACK')->once()->andReturn(Promise::resolved(new Result()));
        $pool->shouldReceive('release')->once()->with($conn);

        $tx = new Transaction($conn, $pool);
        unset($tx);

        await(delay(0.01));

        expect(true)->toBeTrue();
    });

    it('__destruct only releases the pool (no second ROLLBACK) when already inactive', function (): void {
        [$conn, $pool] = makeTxMocks();
        $conn->shouldReceive('query')->with('ROLLBACK')->once()->andReturn(Promise::resolved(new Result()));
        $pool->shouldReceive('release')->once();

        $tx = new Transaction($conn, $pool);
        await($tx->rollback());

        unset($tx);

        await(delay(0.01));

        expect(true)->toBeTrue();
    });
});
