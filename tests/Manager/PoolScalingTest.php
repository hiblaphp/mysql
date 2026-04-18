<?php

declare(strict_types=1);

use Hibla\Mysql\Exceptions\PoolException;
use Hibla\Mysql\Manager\PoolManager;
use Hibla\Promise\Exceptions\TimeoutException;
use Hibla\Promise\Promise;

use function Hibla\await;
use function Hibla\delay;

describe('Pool Scaling and Capacity', function (): void {
    it('warms up exactly to minSize on construction', function (): void {
        $config = testMysqlConfig();
        $pool = new PoolManager($config, maxSize: 10, minSize: 3);

        $attempts = 0;

        while ($pool->stats['active_connections'] < 3 && $attempts < 30) {
            await(delay(0.1));
            $attempts++;
        }

        expect($pool->stats['active_connections'])->toBe(3);
        $pool->close();
    });

    it('maintains 0 connections if minSize is 0', function (): void {
        $pool = new PoolManager(testMysqlConfig(), maxSize: 5, minSize: 0);
        await(delay(0.1));

        expect($pool->stats['active_connections'])->toBe(0);
        $pool->close();
    });

    it('grows connections on-demand up to maxSize', function (): void {
        $pool = new PoolManager(testMysqlConfig(), maxSize: 3, minSize: 1);

        $c1 = await($pool->get());
        $c2 = await($pool->get());
        $c3 = await($pool->get());

        expect($pool->stats['active_connections'])->toBe(3);
        expect($pool->stats['pooled_connections'])->toBe(0);

        $pool->release($c1);
        $pool->release($c2);
        $pool->release($c3);
        $pool->close();
    });

    it('does not exceed maxSize under heavy concurrent burst', function (): void {
        $pool = new PoolManager(testMysqlConfig(), maxSize: 5, minSize: 1);

        $promises = [];
        for ($i = 0; $i < 20; $i++) {
            $promises[] = $pool->get();
        }

        await(delay(0.2));

        expect($pool->stats['active_connections'])->toBe(5);
        expect($pool->stats['waiting_requests'])->toBe(15);

        $conns = [];
        foreach ($promises as $p) {
            if (! $p->isPending()) {
                $c = await($p);
                $conns[] = $c;
                $pool->release($c);
            }
        }

        $pool->close();
    });

    it('queues excess requests as waiters when at maxSize', function (): void {
        $pool = new PoolManager(testMysqlConfig(), maxSize: 2, minSize: 1);

        $c1 = await($pool->get());
        $c2 = await($pool->get());

        $waiterPromise = $pool->get();

        expect($pool->stats['waiting_requests'])->toBe(1);
        expect($waiterPromise->isPending())->toBeTrue();

        $pool->release($c1);
        $c3 = await($waiterPromise);

        expect($c3)->toBe($c1);
        $pool->release($c2);
        $pool->release($c3);
        $pool->close();
    });

    it('resolves multiple waiters in FIFO order', function (): void {
        $pool = new PoolManager(testMysqlConfig(), maxSize: 1, minSize: 1);
        $c1 = await($pool->get());

        $w1 = $pool->get();
        $w2 = $pool->get();

        $order = [];
        $w1->then(function () use (&$order) {
            $order[] = 1;
        });
        $w2->then(function () use (&$order) {
            $order[] = 2;
        });

        $pool->release($c1);
        await(delay(0.05));

        expect($order)->toBe([1]);

        $pool->release($c1);
        await(delay(0.05));
        expect($order)->toBe([1, 2]);

        $pool->close();
    });

    it('replenishes back to minSize when a connection is closed externally', function (): void {
        $pool = new PoolManager(testMysqlConfig(), maxSize: 5, minSize: 2);

        $attempts = 0;
        while ($pool->stats['active_connections'] < 2 && $attempts < 30) {
            await(delay(0.1));
            $attempts++;
        }

        $c1 = await($pool->get());
        $c1->close();
        $pool->release($c1);

        $attempts = 0;
        while ($pool->stats['active_connections'] < 2 && $attempts < 30) {
            await(delay(0.1));
            $attempts++;
        }

        expect($pool->stats['active_connections'])->toBe(2);

        $pool->close();
    });

    it('evicts idle connections exceeding idleTimeout', function (): void {
        $pool = new PoolManager(testMysqlConfig(), maxSize: 5, minSize: 0, idleTimeout: 1);

        $c1 = await($pool->get());
        $pool->release($c1);

        expect($pool->stats['pooled_connections'])->toBe(1);

        await(delay(1.5));

        $c2 = await($pool->get());
        expect($pool->stats['active_connections'])->toBe(1);
        expect($c2)->not->toBe($c1);

        $pool->release($c2);
        $pool->close();
    });

    it('discards connections exceeding maxLifetime on release', function (): void {
        $pool = new PoolManager(testMysqlConfig(), maxSize: 5, minSize: 0, maxLifetime: 1);

        $c1 = await($pool->get());
        await(delay(1.5));

        $pool->release($c1);

        await(delay(0.1));
        expect($pool->stats['pooled_connections'])->toBe(0);

        $pool->close();
    });

    it('tracks connections in draining state when query is cancelled', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        // Increased to 5s so it definitely doesn't complete naturally if the kill takes a second
        $promise = $client->query('SELECT SLEEP(5)');
        await(delay(0.1));
        $promise->cancel();

        try {
            await($promise);
        } catch (Throwable $e) {
        }

        expect($client->stats['draining_connections'])->toBe(1);

        // Poll for up to 4s for the KILL connection to finish and DO SLEEP(0) to resolve
        $attempts = 0;
        while ($client->stats['draining_connections'] > 0 && $attempts < 40) {
            await(delay(0.1));
            $attempts++;
        }

        expect($client->stats['draining_connections'])->toBe(0);
        expect($client->stats['pooled_connections'])->toBe(1);

        $client->close();
    });

    it('triggers onConnect hook after a COM_RESET_CONNECTION release', function (): void {
        $hookCount = 0;
        $client = makeOnConnectClient(
            maxConnections: 1,
            resetConnection: true,
            onConnect: function () use (&$hookCount) {
                $hookCount++;
            }
        );

        await($client->query('SELECT 1'));
        await($client->query('SELECT 1'));

        expect($hookCount)->toBe(2);
        $client->close();
    });

    it('MysqlClient::query reuses connections efficiently', function (): void {
        $client = makeClient(maxConnections: 1);

        await($client->query('SELECT 1'));
        await($client->query('SELECT 1'));

        expect($client->stats['active_connections'])->toBe(1);
        expect($client->stats['pooled_connections'])->toBe(1);
        $client->close();
    });

    it('MysqlClient::stream holds connection until loop ends', function (): void {
        $client = makeClient(maxConnections: 1);

        // Huge payload ensures it takes multiple TCP packets, triggering proper backpressure limits
        // to prevent the EOF from arriving before iteration.
        $stream = await($client->stream("SELECT REPEAT('A', 1000000) UNION ALL SELECT REPEAT('B', 1000000)", [], 1));

        expect($client->stats['active_connections'])->toBe(1);
        expect($client->stats['pooled_connections'])->toBe(0);

        foreach ($stream as $row) {
            // Processing...
        }

        await(delay(0.05));
        expect($client->stats['pooled_connections'])->toBe(1);
        $client->close();
    });

    it('transaction() helper locks connection for the duration', function (): void {
        $client = makeClient(maxConnections: 1);

        $p1 = $client->transaction(function ($tx) {
            await(delay(0.2));

            return await($tx->fetchValue('SELECT 1'));
        });

        await(delay(0.05));

        $p2 = $client->query('SELECT 2');

        expect($client->stats['waiting_requests'])->toBe(1);
        [$v1, $v2] = await(Promise::all([$p1, $p2]));
        expect($v1)->toBe('1');

        $client->close();
    });

    it('respects acquireTimeout when pool is full', function (): void {
        $client = makeTimeoutClient(maxConnections: 1, acquireTimeout: 0.2);

        $p1 = $client->query('SELECT SLEEP(1)');

        $start = microtime(true);

        try {
            await($client->query('SELECT 1'));
            $this->fail('Did not timeout');
        } catch (TimeoutException $e) {
            $elapsed = microtime(true) - $start;
            expect($elapsed)->toBeGreaterThanOrEqual(0.2);
        }

        await($p1);
        $client->close();
    });

    it('rejects with PoolException when maxWaiters is exceeded', function (): void {
        $client = makeWaiterClient(maxConnections: 1, maxWaiters: 1);

        $client->query('SELECT SLEEP(1)');
        $client->query('SELECT 1');

        expect(fn () => await($client->query('SELECT 1')))
            ->toThrow(PoolException::class)
        ;

        $client->close();
    });

    it('cleans up properly on closeAsync with active and draining connections', function (): void {
        $client = makeClient(maxConnections: 2, enableServerSideCancellation: true);

        $p1 = $client->query('SELECT SLEEP(2)');

        $p2 = $client->query('SELECT SLEEP(2)');
        await(delay(0.1));
        $p2->cancel();

        $shutdownPromise = $client->closeAsync();

        expect($client->stats['is_graceful_shutdown'])->toBeTrue();

        await($shutdownPromise);

        // Once graceful shutdown completes, the pool is destroyed and nulled entirely
        expect(fn () => $client->stats)->toThrow(Hibla\Mysql\Exceptions\NotInitializedException::class);
    });

    it('scales up and down during high concurrent SP multiplexing', function (): void {
        $client = makeClient(minConnections: 0, maxConnections: 10);

        $promises = [];
        for ($i = 0; $i < 30; $i++) {
            $promises[] = $client->query('SELECT 1');
        }

        await(Promise::all($promises));

        expect($client->stats['active_connections'])->toBe(10);
        expect($client->stats['pooled_connections'])->toBe(10);

        $client->close();
    });

    it('handles waiter cancellation before connection is acquired', function (): void {
        $pool = new PoolManager(testMysqlConfig(), maxSize: 1);
        $c1 = await($pool->get());

        $w1 = $pool->get();
        $w2 = $pool->get();

        $w1->cancel();

        $pool->release($c1);

        $c2 = await($w2);
        expect($c2)->toBe($c1);

        $pool->release($c2);
        $pool->close();
    });

    it('properly cleans up when client is unset (GC test)', function (): void {
        $client = makeClient(minConnections: 2);

        $attempts = 0;
        while ($client->stats['active_connections'] < 2 && $attempts < 30) {
            await(delay(0.1));
            $attempts++;
        }

        $stats = $client->stats;
        expect($stats['active_connections'])->toBe(2);

        unset($client);
    });

    it('recovers from "onConnect" failure without leaking active connection count', function (): void {
        $failHook = function () {
            throw new RuntimeException('Bad Hook');
        };
        $pool = new PoolManager(testMysqlConfig(), maxSize: 1, onConnect: $failHook);

        try {
            await($pool->get());
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('Bad Hook');
        }

        expect($pool->stats['active_connections'])->toBe(0);
        $pool->close();
    });

    it('recovers from "DO SLEEP(0)" error during drain', function (): void {
        $client = makeClient(maxConnections: 1, enableServerSideCancellation: true);

        $p = $client->query('SELECT SLEEP(1)');
        await(delay(0.1));
        $p->cancel();

        expect($client->stats['draining_connections'])->toBe(1);

        $attempts = 0;

        while ($client->stats['draining_connections'] > 0 && $attempts < 20) {
            await(delay(0.1));
            $attempts++;
        }

        expect($client->stats['pooled_connections'])->toBe(1);
        $client->close();
    });

    it('multiplexes concurrent SP calls accurately across scaled connections', function (): void {
        $client = makeClient(minConnections: 2, maxConnections: 5);

        $promises = [];
        for ($i = 0; $i < 5; $i++) {
            $promises[] = $client->query('SELECT 1');
        }

        await(Promise::all($promises));
        expect($client->stats['active_connections'])->toBe(5);

        $client->close();
    });
});
