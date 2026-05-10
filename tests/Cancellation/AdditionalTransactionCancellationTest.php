<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\CancelledException;
use Hibla\Sql\Exceptions\TransactionException;

use function Hibla\await;
use function Hibla\delay;

describe('Transaction Cancellation - Basic APIs', function (): void {

    it('cancels query() and taints the transaction', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());

        $promise = $tx->query('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        expect(fn () => await($tx->commit()))
            ->toThrow(TransactionException::class, 'Transaction aborted due to a previous query error')
        ;

        await($tx->rollback());

        $result = await($client->query('SELECT 1 AS ok'));
        expect((int) $result->fetchOne()['ok'])->toBe(1);

        $client->close();
    });

    it('cancels execute() and taints the transaction', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());

        $promise = $tx->execute('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        await($tx->rollback());

        $result = await($client->query('SELECT 1 AS ok'));
        expect((int) $result->fetchOne()['ok'])->toBe(1);

        $client->close();
    });

    it('cancels executeGetId() and taints the transaction', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());

        $promise = $tx->executeGetId('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        await($tx->rollback());
        expect($client->stats['active_connections'])->toBe(0);

        $client->close();
    });

    it('cancels fetchOne() and taints the transaction', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());

        $promise = $tx->fetchOne('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        await($tx->rollback());
        expect($client->stats['active_connections'])->toBe(0);

        $client->close();
    });

    it('cancels fetchValue() and taints the transaction', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());

        $promise = $tx->fetchValue('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        await($tx->rollback());
        expect($client->stats['active_connections'])->toBe(0);

        $client->close();
    });

    it('cancels a later query() after earlier queries succeeded', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        await($client->query('CREATE TEMPORARY TABLE txn_cancel_mid (id INT)'));

        $tx = await($client->beginTransaction());
        await($tx->query('INSERT INTO txn_cancel_mid VALUES (1)'));
        await($tx->query('INSERT INTO txn_cancel_mid VALUES (2)'));

        $slow = $tx->query('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $slow->cancel());

        expect(fn () => await($slow))->toThrow(CancelledException::class);

        expect(fn () => await($tx->commit()))
            ->toThrow(TransactionException::class)
        ;

        await($tx->rollback());

        $count = await($client->fetchValue('SELECT COUNT(*) FROM txn_cancel_mid'));
        expect((int) $count)->toBe(0);

        $client->close();
    });
});

describe('Transaction Cancellation - Streams & Prepared Statements', function (): void {

    it('cancels stream() before first row and taints the transaction', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());

        $promise = $tx->stream('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        await($tx->rollback());
        expect($client->stats['active_connections'])->toBe(0);

        $client->close();
    });

    it('cancels stream() mid-iteration and taints the transaction', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());

        $stream = await($tx->stream(twentyRowSql()));

        $rowsRead = 0;
        foreach ($stream as $row) {
            $rowsRead++;
            if ($rowsRead >= 3) {
                $stream->cancel();

                break;
            }
        }

        expect($stream->isCancelled())->toBeTrue();

        expect(fn () => await($tx->commit()))
            ->toThrow(TransactionException::class)
        ;

        await($tx->rollback());

        $result = await($client->query('SELECT 1 AS ok'));
        expect((int) $result->fetchOne()['ok'])->toBe(1);

        $client->close();
    });

    it('cancels an in-transaction prepared statement execute()', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());

        $stmt = await($tx->prepare('SELECT SLEEP(?)'));

        $promise = $stmt->execute([2]);
        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        await($tx->rollback());

        $result = await($client->query('SELECT 1 AS ok'));
        expect((int) $result->fetchOne()['ok'])->toBe(1);

        $client->close();
    });

    it('cancels an in-transaction prepared statement executeStream() mid-iteration', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());
        $stmt = await($tx->prepare(twentyRowPreparedSql()));

        $stream = await($stmt->executeStream([]));

        $rowsRead = 0;
        foreach ($stream as $row) {
            $rowsRead++;
            if ($rowsRead >= 5) {
                $stream->cancel();

                break;
            }
        }

        expect($stream->isCancelled())->toBeTrue();

        expect(fn () => await($tx->commit()))
            ->toThrow(TransactionException::class)
        ;

        await($tx->rollback());

        $result = await($client->query('SELECT 1 AS ok'));
        expect((int) $result->fetchOne()['ok'])->toBe(1);

        $client->close();
    });

    it('cancels stream() outer promise before it resolves and leaves pool healthy', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());

        $promise = $tx->stream(twentyRowSql());
        $promise->cancel();

        try {
            await($promise);
        } catch (CancelledException) {
        }

        await($tx->rollback());

        await(delay(0.1));
        expect($client->stats['active_connections'])->toBe(0);

        $client->close();
    });
});

describe('Transaction Cancellation - Savepoints', function (): void {

    it('cancels savepoint() creation and taints the transaction', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());
        await($tx->query('SELECT 1'));

        $promise = $tx->savepoint('slow_savepoint');
        $promise->cancel();

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        expect(fn () => await($tx->commit()))
            ->toThrow(TransactionException::class, 'Transaction aborted due to a previous query error')
        ;

        await($tx->rollback());
        expect($client->stats['active_connections'])->toBe(0);

        $client->close();
    });

    it('cancels releaseSavepoint() and taints the transaction', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());
        await($tx->savepoint('sp2'));

        $promise = $tx->releaseSavepoint('sp2');
        $promise->cancel();

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        expect(fn () => await($tx->commit()))
            ->toThrow(TransactionException::class)
        ;

        await($tx->rollback());
        expect($client->stats['active_connections'])->toBe(0);

        $client->close();
    });

    it('data inserted before savepoint is rolled back when transaction is cancelled after the savepoint', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        await($client->query('CREATE TEMPORARY TABLE sp_cancel_test (id INT)'));

        $tx = await($client->beginTransaction());
        await($tx->query('INSERT INTO sp_cancel_test VALUES (1)'));
        await($tx->savepoint('mid'));
        await($tx->query('INSERT INTO sp_cancel_test VALUES (2)'));

        $slow = $tx->query('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $slow->cancel());

        expect(fn () => await($slow))->toThrow(CancelledException::class);

        await($tx->rollback());

        $count = await($client->fetchValue('SELECT COUNT(*) FROM sp_cancel_test'));
        expect((int) $count)->toBe(0);

        $client->close();
    });
});

describe('Transaction Cancellation - Tainted State', function (): void {

    it('further query() calls on a tainted transaction are rejected with TransactionException', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());

        $promise = $tx->query('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $promise->cancel());

        try {
            await($promise);
        } catch (CancelledException) {
        }

        expect(fn () => await($tx->query('SELECT 1')))->toThrow(TransactionException::class);

        await($tx->rollback());
        expect($client->stats['active_connections'])->toBe(0);

        $client->close();
    });

    it('rollback() succeeds on a tainted transaction and returns the connection to idle', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());

        $promise = $tx->query('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        await($tx->rollback());

        expect($tx->isActive())->toBeFalse();
        expect($client->stats['active_connections'])->toBe(0);

        $client->close();
    });

    it('isActive() returns false after rollback on a tainted transaction', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());

        $promise = $tx->query('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        await($tx->rollback());

        expect($tx->isActive())->toBeFalse();

        $client->close();
    });

    it('calling rollback() twice on a tainted transaction is idempotent', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());

        $promise = $tx->query('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        await($tx->rollback());
        await($tx->rollback());

        expect($client->stats['active_connections'])->toBe(0);

        $client->close();
    });

    it('commit() after taint is rejected and rollback() still recovers the connection', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());

        $promise = $tx->query('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        try {
            await($tx->commit());
        } catch (TransactionException) {
        }

        await($tx->rollback());

        $result = await($client->query('SELECT 42 AS val'));
        expect((int) $result->fetchOne()['val'])->toBe(42);

        $client->close();
    });
});

describe('Transaction Cancellation - Commit Lifecycle', function (): void {

    it('cancels commit() mid-flight and leaves the transaction closed', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        await($client->query('CREATE TEMPORARY TABLE commit_cancel_test (id INT)'));

        $tx = await($client->beginTransaction());
        await($tx->query('INSERT INTO commit_cancel_test VALUES (1)'));

        $commit = $tx->commit();
        $commit->cancel();

        expect(fn () => await($commit))->toThrow(CancelledException::class);

        await(delay(0.3));

        expect($tx->isActive())->toBeFalse();
        expect($client->stats['active_connections'])->toBe(0);

        $client->close();
    });

    it('pool is usable after a commit cancellation', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());
        await($tx->query('SELECT 1'));

        $commit = $tx->commit();
        Loop::addTimer(0.05, fn () => $commit->cancel());

        try {
            await($commit);
        } catch (CancelledException) {
        }

        await(delay(0.3));

        $result = await($client->query('SELECT 99 AS val'));
        expect((int) $result->fetchOne()['val'])->toBe(99);

        $client->close();
    });
});

describe('Transaction Cancellation - Auto-managed Wrapper', function (): void {

    it('auto-rolls back when a query is cancelled inside the transaction() wrapper', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $promise = $client->transaction(function ($tx) {
            return await($tx->query('SELECT SLEEP(2)'));
        });

        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        expect($client->stats['active_connections'])->toBe(0);

        $result = await($client->query('SELECT 1 AS ok'));
        expect((int) $result->fetchOne()['ok'])->toBe(1);

        $client->close();
    });

    it('transaction() wrapper does not retry when the promise is cancelled externally', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);
        $attempts = 0;

        $options = new Hibla\Sql\TransactionOptions(
            attempts: 5,
            retryableExceptions: [RuntimeException::class],
        );

        $promise = $client->transaction(function ($tx) use (&$attempts) {
            $attempts++;

            return await($tx->query('SELECT SLEEP(10)'));
        }, $options);

        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        expect($attempts)->toBe(1);

        $client->close();
    });

    it('transaction() wrapper rolls back and data is not persisted when cancelled', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        await($client->query('CREATE TEMPORARY TABLE wrapper_cancel_test (id INT)'));

        $promise = $client->transaction(function ($tx) {
            await($tx->query('INSERT INTO wrapper_cancel_test VALUES (1)'));
            await($tx->query('INSERT INTO wrapper_cancel_test VALUES (2)'));

            return await($tx->query('SELECT SLEEP(5)'));
        });

        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        await(delay(0.3));

        $count = await($client->fetchValue('SELECT COUNT(*) FROM wrapper_cancel_test'));
        expect((int) $count)->toBe(0);

        $client->close();
    });

    it('auto-rolls back and releases connection when a tainted transaction is garbage collected', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $createAndCancelTx = function () use ($client): void {
            $tx = await($client->beginTransaction());

            $promise = $tx->query('SELECT SLEEP(2)');
            Loop::addTimer(0.1, fn () => $promise->cancel());

            try {
                await($promise);
            } catch (CancelledException) {
            }
        };

        $createAndCancelTx();

        gc_collect_cycles();

        await(delay(0.2));

        expect($client->stats['active_connections'])->toBe(0);

        $result = await($client->query('SELECT 1 AS ok'));
        expect((int) $result->fetchOne()['ok'])->toBe(1);

        $client->close();
    });
});

describe('Transaction Cancellation - Concurrent Transaction Isolation', function (): void {

    it('cancelling one transaction does not affect a concurrent transaction on a different connection', function (): void {
        $client = makeClient(maxConnections: 2, enableServerSideCancellation: true);

        $tx1 = await($client->beginTransaction());
        $tx2 = await($client->beginTransaction());

        $slow = $tx1->query('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $slow->cancel());

        expect(fn () => await($slow))->toThrow(CancelledException::class);

        $result = await($tx2->query('SELECT 42 AS val'));
        expect((int) $result->fetchOne()['val'])->toBe(42);

        await($tx1->rollback());
        await($tx2->commit());

        expect($client->stats['active_connections'])->toBe(0);

        $client->close();
    });

    it('both transactions can be cancelled independently without cross-contamination', function (): void {
        $client = makeClient(maxConnections: 2, enableServerSideCancellation: true);

        $tx1 = await($client->beginTransaction());
        $tx2 = await($client->beginTransaction());

        $s1 = $tx1->query('SELECT SLEEP(2)');
        $s2 = $tx2->query('SELECT SLEEP(2)');

        Loop::addTimer(0.1, fn () => $s1->cancel());
        Loop::addTimer(0.15, fn () => $s2->cancel());

        expect(fn () => await($s1))->toThrow(CancelledException::class);
        expect(fn () => await($s2))->toThrow(CancelledException::class);

        await($tx1->rollback());
        await($tx2->rollback());

        expect($client->stats['active_connections'])->toBe(0);

        $result = await($client->query('SELECT 1 AS ok'));
        expect((int) $result->fetchOne()['ok'])->toBe(1);

        $client->close();
    });

    it('a cancelled transaction does not block a waiter from getting a fresh connection', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $tx = await($client->beginTransaction());

        $waiter = $client->query('SELECT 77 AS val');

        $slow = $tx->query('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $slow->cancel());

        expect(fn () => await($slow))->toThrow(CancelledException::class);

        await($tx->rollback());

        $result = await($waiter);
        expect((int) $result->fetchOne()['val'])->toBe(77);

        $client->close();
    });
});

describe('Transaction Cancellation - Opt-out', function (): void {

    it('closes the connection entirely when cancellation opt-out is active inside a transaction', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: false);

        $tx = await($client->beginTransaction());

        $promise = $tx->query('SELECT SLEEP(2)');
        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        expect($tx->isClosed())->toBeTrue();

        try {
            await($tx->rollback());
        } catch (Throwable) {
        }

        $result = await($client->query('SELECT 1 AS ok'));
        expect((int) $result->fetchOne()['ok'])->toBe(1);

        $client->close();
    });

    it('pool opens a fresh connection for the next caller after an opt-out transaction cancel', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: false);

        $tx = await($client->beginTransaction());

        $waiter = $client->query('SELECT 55 AS val');
        $promise = $tx->query('SELECT SLEEP(2)');

        Loop::addTimer(0.1, fn () => $promise->cancel());

        expect(fn () => await($promise))->toThrow(CancelledException::class);

        try {
            await($tx->rollback());
        } catch (Throwable) {
        }

        $result = await($waiter);
        expect((int) $result->fetchOne()['val'])->toBe(55);

        $client->close();
    });
});
