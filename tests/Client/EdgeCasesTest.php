<?php

declare(strict_types=1);

use Hibla\Mysql\Exceptions\PoolException;
use Hibla\Mysql\Interfaces\ConnectionSetup;
use Hibla\Promise\Promise;
use Hibla\Sql\Exceptions\QueryException;
use Hibla\Sql\TransactionOptions;

use function Hibla\await;
use function Hibla\delay;

describe('Statement Cache - edge cases', function (): void {

    it('does not share cached statements across different physical connections', function (): void {
        $client = makeClient(maxConnections: 2, enableStatementCache: true);

        $sql = 'SELECT ? AS val';

        [$r1, $r2] = await(Promise::all([
            $client->query($sql, [7]),
            $client->query($sql, [8]),
        ]));

        expect((int) $r1->fetchOne()['val'])->toBe(7)
            ->and((int) $r2->fetchOne()['val'])->toBe(8)
        ;

        $client->close();
    });

    it('cache entry is evicted and statement is closed when cache is full', function (): void {
        $client = makeClient(
            maxConnections: 1,
            enableStatementCache: true,
            statementCacheSize: 1,
        );

        await($client->query('SELECT ? AS a', [1]));
        await($client->query('SELECT ? AS b', [2]));
        $result = await($client->query('SELECT ? AS a', [99]));

        expect((int) $result->fetchOne()['a'])->toBe(99);

        $client->close();
    });

    it('cache is rebuilt per-connection after COM_RESET_CONNECTION clears session state', function (): void {
        $client = makeResetClient(maxConnections: 1);

        $sql = 'SELECT ? AS val';

        await($client->query($sql, [1]));
        await($client->query($sql, [2]));

        $result = await($client->query($sql, [3]));
        expect((int) $result->fetchOne()['val'])->toBe(3);

        $client->close();
    });

    it('clearStatementCache() does not break subsequent parameterized queries', function (): void {
        $client = makeClient(maxConnections: 1, enableStatementCache: true);

        $sql = 'SELECT ? AS val';

        await($client->query($sql, [10]));
        $client->clearStatementCache();

        $result = await($client->query($sql, [20]));

        expect((int) $result->fetchOne()['val'])->toBe(20);

        $client->close();
    });

    it('cache is bypassed and statement is closed after each use when disabled', function (): void {
        $client = makeClient(maxConnections: 1, enableStatementCache: false);

        $sql = 'SELECT ? AS val';

        $r1 = await($client->query($sql, [1]));
        $r2 = await($client->query($sql, [2]));

        expect((int) $r1->fetchOne()['val'])->toBe(1)
            ->and((int) $r2->fetchOne()['val'])->toBe(2)
        ;

        $client->close();
    });
});

describe('Connection lifecycle - edge cases', function (): void {

    it('discards a connection that has exceeded idleTimeout on next borrow', function (): void {
        $client = makeClient(maxConnections: 1, idleTimeout: 1);

        await($client->query('SELECT 1'));

        await(delay(1.2));

        $result = await($client->query('SELECT 42 AS val'));
        expect((int) $result->fetchOne()['val'])->toBe(42);

        $client->close();
    });

    it('discards a connection that has exceeded maxLifetime on next borrow', function (): void {
        $client = makeClient(maxConnections: 1, maxLifetime: 1, idleTimeout: 300);

        await($client->query('SELECT 1'));

        await(delay(1.2));

        $result = await($client->query('SELECT 99 AS val'));
        expect((int) $result->fetchOne()['val'])->toBe(99);

        $client->close();
    });

    it('pool stays within maxConnections under a burst of concurrent requests', function (): void {
        $client = makeClient(maxConnections: 3);

        await(Promise::all(array_map(
            fn () => $client->query('SELECT SLEEP(0.05)'),
            range(1, 6)
        )));

        expect($client->stats['total_connections'])->toBeLessThanOrEqual(3);

        $client->close();
    });

    it('minConnections connections are replenished after forced eviction', function (): void {
        $client = makeClient(maxConnections: 5, minConnections: 2);

        await(delay(0.2));

        await($client->healthCheck());
        await(delay(0.2));

        expect($client->stats['total_connections'])->toBeGreaterThanOrEqual(2);

        $client->close();
    });
});

describe('onConnect hook - edge cases', function (): void {

    it('re-runs the hook after COM_RESET_CONNECTION when resetConnection is enabled', function (): void {
        $callCount = 0;

        $client = makeOnConnectClient(
            maxConnections: 1,
            resetConnection: true,
            onConnect: function () use (&$callCount): void {
                $callCount++;
            },
        );

        await($client->query('SELECT 1'));
        await($client->query('SELECT 2'));

        expect($callCount)->toBe(2);

        $client->close();
    });

    it('drops the connection and satisfies the waiter when the onConnect hook throws', function (): void {
        $attempts = 0;

        $client = makeOnConnectClient(
            maxConnections: 1,
            onConnect: function () use (&$attempts): void {
                $attempts++;
                if ($attempts === 1) {
                    throw new RuntimeException('Hook failure');
                }
            },
        );

        try {
            await($client->query('SELECT 1'));
        } catch (RuntimeException) {
        }

        $result = await($client->query('SELECT 42 AS val'));
        expect((int) $result->fetchOne()['val'])->toBe(42);

        $client->close();
    });

    it('supports an async (promise-returning) onConnect hook', function (): void {
        $applied = false;

        $client = makeOnConnectClient(
            maxConnections: 1,
            onConnect: function (ConnectionSetup $setup) use (&$applied): Hibla\Promise\Interfaces\PromiseInterface {
                return $setup->query("SET SESSION time_zone = '+00:00'")->then(
                    function () use (&$applied): void {
                        $applied = true;
                    }
                );
            },
        );

        await($client->query('SELECT 1'));

        expect($applied)->toBeTrue();

        $client->close();
    });
});

describe('fetchValue() - edge cases', function (): void {

    it('returns the value for a named column when multiple columns are present', function (): void {
        $client = makeClient();

        $val = await($client->fetchValue('SELECT 10 AS a, 20 AS b', 'b'));

        expect((int) $val)->toBe(20);

        $client->close();
    });

    it('returns null when the column value itself is SQL NULL', function (): void {
        $client = makeClient();

        $val = await($client->fetchValue('SELECT NULL AS val'));

        expect($val)->toBeNull();

        $client->close();
    });

    it('returns null when the named column exists but holds NULL', function (): void {
        $client = makeClient();

        $val = await($client->fetchValue('SELECT NULL AS name', 'name'));

        expect($val)->toBeNull();

        $client->close();
    });

    it('returns null when the query returns no rows', function (): void {
        $client = makeClient();

        await($client->query('CREATE TEMPORARY TABLE fetchval_empty (id INT)'));

        $val = await($client->fetchValue('SELECT id FROM fetchval_empty'));

        expect($val)->toBeNull();

        $client->close();
    });
});

describe('executeGetId() - edge cases', function (): void {

    it('returns the AUTO_INCREMENT id after a successful insert', function (): void {
        $client = makeClient();

        await($client->query(
            'CREATE TEMPORARY TABLE getid_test (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(10))'
        ));

        $id = await($client->executeGetId("INSERT INTO getid_test (v) VALUES ('hello')"));

        expect($id)->toBeGreaterThan(0);

        $client->close();
    });

    it('returns 0 when the statement affects no rows and has no insert id', function (): void {
        $client = makeClient();

        await($client->query('CREATE TEMPORARY TABLE getid_noop (id INT)'));

        $id = await($client->executeGetId('UPDATE getid_noop SET id = 1 WHERE id = 999'));

        expect($id)->toBe(0);

        $client->close();
    });

    it('returns sequential ids for multiple inserts', function (): void {
        $client = makeClient();

        await($client->query(
            'CREATE TEMPORARY TABLE getid_seq (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(10))'
        ));

        $id1 = await($client->executeGetId("INSERT INTO getid_seq (v) VALUES ('a')"));
        $id2 = await($client->executeGetId("INSERT INTO getid_seq (v) VALUES ('b')"));

        expect($id2)->toBe($id1 + 1);

        $client->close();
    });
});

describe('stream() - edge cases', function (): void {

    it('streams a parameterized query when statement cache is disabled', function (): void {
        $client = makeClient(maxConnections: 1, enableStatementCache: false);

        $stream = await($client->stream(twentyRowPreparedSql() . ' LIMIT ?', [5]));

        $rows = [];
        foreach ($stream as $row) {
            $rows[] = (int) $row['n'];
        }

        expect($rows)->toHaveCount(5)
            ->and($rows[0])->toBe(1)
            ->and($rows[4])->toBe(5)
        ;

        $client->close();
    });

    it('handles an empty result set without error', function (): void {
        $client = makeClient();

        await($client->query('CREATE TEMPORARY TABLE empty_stream_tbl (id INT)'));

        $stream = await($client->stream('SELECT * FROM empty_stream_tbl'));
        $rows = [];

        foreach ($stream as $row) {
            $rows[] = $row;
        }

        expect($rows)->toBeEmpty();

        $client->close();
    });

    it('releases the connection after an error before any row is yielded', function (): void {
        $client = makeClient(maxConnections: 1);

        try {
            $stream = await($client->stream('SELECT * FROM no_such_table_xyz'));
            foreach ($stream as $row) {
            }
        } catch (Throwable) {
        }

        $result = await($client->query('SELECT 1 AS ok'));
        expect((int) $result->fetchOne()['ok'])->toBe(1);

        $client->close();
    });

    it('can stream the same SQL concurrently on two connections', function (): void {
        $client = makeClient(maxConnections: 2);

        [$s1, $s2] = await(Promise::all([
            $client->stream(twentyRowSql() . ' LIMIT 3'),
            $client->stream(twentyRowSql() . ' LIMIT 3 OFFSET 3'),
        ]));

        $a = [];
        foreach ($s1 as $row) {
            $a[] = (int) $row['n'];
        }

        $b = [];
        foreach ($s2 as $row) {
            $b[] = (int) $row['n'];
        }

        expect($a)->toHaveCount(3)
            ->and($b)->toHaveCount(3)
            ->and($a)->not->toEqual($b)
        ;

        $client->close();
    });

    it('releases the connection after the stream is fully consumed', function (): void {
        $client = makeClient(maxConnections: 1);

        $stream = await($client->stream(twentyRowSql() . ' LIMIT 3'));

        foreach ($stream as $row) {
        }

        $result = await($client->query('SELECT 1 AS ok'));
        expect((int) $result->fetchOne()['ok'])->toBe(1);
        expect($client->stats['active_connections'])->toBe(0);

        $client->close();
    });
});

describe('multiStatements - edge cases', function (): void {

    it('executes stacked statements when multiStatements is enabled', function (): void {
        $client = makeMultiStatementClient(maxConnections: 1);

        $result = await($client->query('SELECT 1 AS a; SELECT 2 AS b'));

        expect((int) $result->fetchOne()['a'])->toBe(1);

        $follow = await($client->query('SELECT 3 AS c'));
        expect((int) $follow->fetchOne()['c'])->toBe(3);

        $client->close();
    });

    it('connection is reusable after a multi-statement query with three result sets', function (): void {
        $client = makeMultiStatementClient(maxConnections: 1);

        await($client->query('SELECT 1; SELECT 2; SELECT 3'));

        expect($client->stats['active_connections'])->toBe(0);

        $result = await($client->query('SELECT 99 AS val'));
        expect((int) $result->fetchOne()['val'])->toBe(99);

        $client->close();
    });
});

describe('Transactions - edge cases', function (): void {

    it('beginTransaction() returns a transaction that commits successfully', function (): void {
        $client = makeManualTransactionClient();

        await($client->query(
            'CREATE TEMPORARY TABLE txn_commit_test (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(10))'
        ));

        $tx = await($client->beginTransaction());
        await($tx->query("INSERT INTO txn_commit_test (v) VALUES ('x')"));
        await($tx->commit());

        $result = await($client->query('SELECT COUNT(*) AS c FROM txn_commit_test'));
        expect((int) $result->fetchOne()['c'])->toBe(1);

        $client->close();
    });

    it('beginTransaction() rollback undoes all changes', function (): void {
        $client = makeManualTransactionClient();

        await($client->query(
            'CREATE TEMPORARY TABLE txn_rollback_test (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(10))'
        ));

        $tx = await($client->beginTransaction());
        await($tx->query("INSERT INTO txn_rollback_test (v) VALUES ('x')"));
        await($tx->rollback());

        $result = await($client->query('SELECT COUNT(*) AS c FROM txn_rollback_test'));
        expect((int) $result->fetchOne()['c'])->toBe(0);

        $client->close();
    });

    it('transaction() auto-commits when the callback returns successfully', function (): void {
        $client = makeTransactionClient();

        await($client->query(
            'CREATE TEMPORARY TABLE txn_auto_commit (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(10))'
        ));

        await($client->transaction(function ($tx): void {
            await($tx->query("INSERT INTO txn_auto_commit (v) VALUES ('hello')"));
        }));

        $result = await($client->query('SELECT COUNT(*) AS c FROM txn_auto_commit'));
        expect((int) $result->fetchOne()['c'])->toBe(1);

        $client->close();
    });

    it('transaction() auto-rolls back and rethrows when the callback throws', function (): void {
        $client = makeTransactionClient();

        await($client->query(
            'CREATE TEMPORARY TABLE txn_auto_rollback (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(10))'
        ));

        try {
            await($client->transaction(function ($tx): void {
                await($tx->query("INSERT INTO txn_auto_rollback (v) VALUES ('x')"));

                throw new RuntimeException('Intentional failure');
            }));
        } catch (RuntimeException) {
        }

        $result = await($client->query('SELECT COUNT(*) AS c FROM txn_auto_rollback'));
        expect((int) $result->fetchOne()['c'])->toBe(0);

        $client->close();
    });

    it('transaction() returns the value returned by the callback', function (): void {
        $client = makeTransactionClient();

        $result = await($client->transaction(fn ($tx) => 'payload'));

        expect($result)->toBe('payload');

        $client->close();
    });

    it('transaction() releases the connection back to the pool after commit', function (): void {
        $client = makeTransactionClient(maxConnections: 1);

        await($client->transaction(function ($tx): void {
            await($tx->query('SELECT 1'));
        }));

        expect($client->stats['active_connections'])->toBe(0);

        $result = await($client->query('SELECT 1 AS ok'));
        expect((int) $result->fetchOne()['ok'])->toBe(1);

        $client->close();
    });

    it('transaction() releases the connection back to the pool after rollback', function (): void {
        $client = makeTransactionClient(maxConnections: 1);

        try {
            await($client->transaction(function ($tx): void {
                await($tx->query('SELECT 1'));

                throw new RuntimeException('Force rollback');
            }));
        } catch (RuntimeException) {
        }

        expect($client->stats['active_connections'])->toBe(0);

        $result = await($client->query('SELECT 1 AS ok'));
        expect((int) $result->fetchOne()['ok'])->toBe(1);

        $client->close();
    });

    it('transaction() with multiple attempts retries on a retryable error', function (): void {
        $client = makeTransactionClient(maxConnections: 1);
        $attempts = 0;

        $options = new TransactionOptions(
            attempts: 3,
            retryableExceptions: [RuntimeException::class],
        );

        await($client->transaction(function ($tx) use (&$attempts): void {
            $attempts++;
            if ($attempts < 3) {
                throw new RuntimeException('Retryable');
            }
        }, $options));

        expect($attempts)->toBe(3);

        $client->close();
    });

    it('transaction() stops immediately on a tier-2 non-retryable exception', function (): void {
        $client = makeTransactionClient(maxConnections: 1);
        $attempts = 0;

        $options = new TransactionOptions(attempts: 5);

        try {
            await($client->transaction(function ($tx) use (&$attempts): void {
                $attempts++;

                throw new QueryException('Non-retryable');
            }, $options));
        } catch (QueryException) {
        }

        expect($attempts)->toBe(1);

        $client->close();
    });

    it('isActive() returns false after commit', function (): void {
        $client = makeManualTransactionClient();

        $tx = await($client->beginTransaction());
        await($tx->commit());

        expect($tx->isActive())->toBeFalse();

        $client->close();
    });

    it('isActive() returns false after rollback', function (): void {
        $client = makeManualTransactionClient();

        $tx = await($client->beginTransaction());
        await($tx->rollback());

        expect($tx->isActive())->toBeFalse();

        $client->close();
    });
});

describe('Shutdown - edge cases', function (): void {

    it('close() while closeAsync() is pending resolves the async shutdown promise', function (): void {
        $client = makeClient(maxConnections: 1);

        $held = $client->query('SELECT SLEEP(10)');
        $held->catch(static fn () => null);

        $resolved = false;
        $asyncDone = $client->closeAsync()->then(static function () use (&$resolved): void {
            $resolved = true;
        });
        $asyncDone->catch(static fn () => null);

        $client->close();

        await(delay(0));

        expect($resolved)->toBeTrue();
    });

    it('closeAsync() rejects all pending waiters immediately', function (): void {
        $client = makeWaiterClient(maxConnections: 1, maxWaiters: 5);

        $held = $client->query('SELECT SLEEP(10)');
        $held->catch(static fn () => null);

        $waiter = $client->query('SELECT 1');
        $waiterError = null;
        $waiter->catch(static function (Throwable $e) use (&$waiterError): void {
            $waiterError = $e;
        });

        $client->closeAsync(timeout: 0.05);

        await(delay(0));

        expect($waiterError)->toBeInstanceOf(PoolException::class);

        $client->close();
    });

    it('stats reflect graceful shutdown state while draining', function (): void {
        $client = makeClient(maxConnections: 2);

        $slow = $client->query('SELECT SLEEP(0.1)');
        $slow->catch(static fn () => null);

        $shutdown = $client->closeAsync();

        $stats = $client->stats;
        expect($stats['is_graceful_shutdown'])->toBeTrue();

        await($shutdown);

        $client->close();
    });

    it('healthCheck() completes without error during graceful shutdown', function (): void {
        $client = makeClient(maxConnections: 2, minConnections: 2);

        await(delay(0.1));

        $shutdown = $client->closeAsync(timeout: 1.0);

        $stats = await($client->healthCheck());

        expect($stats)->toHaveKey('healthy')
            ->toHaveKey('unhealthy')
            ->toHaveKey('total_checked')
        ;

        await($shutdown);
    });

    it('calling query() after closeAsync() returns a PoolException immediately', function (): void {
        $client = makeClient(maxConnections: 1);

        $client->closeAsync(timeout: 5.0);

        expect(fn () => await($client->query('SELECT 1')))
            ->toThrow(PoolException::class)
        ;

        $client->close();
    });
});

describe('Error propagation - edge cases', function (): void {

    it('a duplicate key violation leaves the pool connection usable', function (): void {
        $client = makeClient(maxConnections: 1);

        await($client->query(
            'CREATE TEMPORARY TABLE uniq_test (id INT UNIQUE)'
        ));
        await($client->query('INSERT INTO uniq_test VALUES (1)'));

        try {
            await($client->query('INSERT INTO uniq_test VALUES (1)'));
        } catch (QueryException) {
        }

        $result = await($client->query('SELECT COUNT(*) AS c FROM uniq_test'));
        expect((int) $result->fetchOne()['c'])->toBe(1);

        $client->close();
    });

    it('an error from executeGetId does not leave a dangling connection', function (): void {
        $client = makeClient(maxConnections: 1);

        try {
            await($client->executeGetId('SELECT * FROM no_such_table_xyz'));
        } catch (Throwable) {
        }

        $result = await($client->query('SELECT 55 AS val'));
        expect((int) $result->fetchOne()['val'])->toBe(55);

        $client->close();
    });

    it('multiple interleaved errors across concurrent queries do not corrupt pool state', function (): void {
        $client = makeClient(maxConnections: 3);

        await(Promise::allSettled([
            $client->query('SELECT * FROM no_such_table_1'),
            $client->query('SELECT 2 AS val'),
            $client->query('SELECT * FROM no_such_table_2'),
        ]));

        $r = await($client->query('SELECT 77 AS val'));
        expect((int) $r->fetchOne()['val'])->toBe(77);
        expect($client->stats['active_connections'])->toBe(0);

        $client->close();
    });

    it('does not leave dangling connections after multiple consecutive errors', function (): void {
        $client = makeClient(maxConnections: 1);

        for ($i = 0; $i < 3; $i++) {
            try {
                await($client->query('SELECT * FROM no_such_table_xyz'));
            } catch (Throwable) {
            }
        }

        $result = await($client->query('SELECT 42 AS val'));
        expect((int) $result->fetchOne()['val'])->toBe(42);
        expect($client->stats['active_connections'])->toBe(0);

        $client->close();
    });

    it('an error from execute() does not leave a dangling connection', function (): void {
        $client = makeClient(maxConnections: 1);

        try {
            await($client->execute('INSERT INTO no_such_table VALUES (1)'));
        } catch (Throwable) {
        }

        $result = await($client->query('SELECT 1 AS ok'));
        expect((int) $result->fetchOne()['ok'])->toBe(1);

        $client->close();
    });
});
