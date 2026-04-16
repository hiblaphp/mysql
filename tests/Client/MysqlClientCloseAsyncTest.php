<?php

declare(strict_types=1);

use Hibla\Mysql\Exceptions\NotInitializedException;
use Hibla\Mysql\Exceptions\PoolException;
use Hibla\Promise\Promise;

use function Hibla\async;
use function Hibla\await;

describe('MysqlClient::closeAsync()', function (): void {
    it('resolves immediately when pool is idle', function (): void {
        $client = makeClient();

        $resolved = false;

        await($client->closeAsync()->then(function () use (&$resolved): void {
            $resolved = true;
        }));

        expect($resolved)->toBeTrue();
    });

    it('waits for an in-flight query to complete before resolving', function (): void {
        $client = makeClient();

        $queryFinished = false;

        $queryPromise = $client->query('SELECT SLEEP(1)')
            ->then(function () use (&$queryFinished): void {
                $queryFinished = true;
            })
        ;

        $shutdownPromise = $client->closeAsync();

        await(Promise::all([$queryPromise, $shutdownPromise]));

        expect($queryFinished)->toBeTrue();
    });

    it('rejects new queries submitted after closeAsync() is called', function (): void {
        $client = makeClient();

        $shutdownPromise = $client->closeAsync();

        $rejected = false;

        $lateQuery = $client->query('SELECT 1')
            ->catch(function (Throwable $e) use (&$rejected): void {
                if ($e instanceof PoolException || $e instanceof NotInitializedException) {
                    $rejected = true;
                }
            })
        ;

        await(Promise::all([$shutdownPromise, $lateQuery]));

        expect($rejected)->toBeTrue();
    });

    it('returns the same promise on multiple closeAsync() calls', function (): void {
        $client = makeClient();

        $resolutionCount = 0;

        $p1 = $client->closeAsync()->then(function () use (&$resolutionCount): void {
            $resolutionCount++;
        });

        $p2 = $client->closeAsync()->then(function () use (&$resolutionCount): void {
            $resolutionCount++;
        });

        await(Promise::all([$p1, $p2]));

        expect($resolutionCount)->toBe(2);
    });

    it('resolves the shutdown promise when close() is called mid-drain', function (): void {
        $client = makeClient();

        $client->query('SELECT SLEEP(5)')
            ->catch(function (): void {
                // Expected — connection will be force-closed.
            })
        ;

        $shutdownResolved = false;

        $shutdownPromise = $client->closeAsync()->then(function () use (&$shutdownResolved): void {
            $shutdownResolved = true;
        });

        $client->close();

        await($shutdownPromise);

        expect($shutdownResolved)->toBeTrue();
    });

    it('falls back to force close when timeout expires', function (): void {
        $client = makeClient();

        $client->query('SELECT SLEEP(5)')
            ->catch(function (): void {
                // Expected — killed by force close on timeout.
            })
        ;

        $start = microtime(true);

        await($client->closeAsync(timeout: 1.0));

        $elapsed = microtime(true) - $start;

        expect($elapsed)->toBeLessThan(3.0);
    });

    it('resolves immediately when called after close()', function (): void {
        $client = makeClient();
        $client->close();

        $resolved = false;

        await($client->closeAsync()->then(function () use (&$resolved): void {
            $resolved = true;
        }));

        expect($resolved)->toBeTrue();
    });

    it('waits for multiple concurrent in-flight queries to complete', function (): void {
        $client = makeConcurrentClient(maxConnections: 5);

        $finishedCount = 0;
        $queries = [];

        for ($i = 0; $i < 3; $i++) {
            $queries[] = $client->query('SELECT SLEEP(1)')
                ->then(function () use (&$finishedCount): void {
                    $finishedCount++;
                })
            ;
        }

        $shutdownPromise = $client->closeAsync();

        await(Promise::all([...$queries, $shutdownPromise]));

        expect($finishedCount)->toBe(3);
    });

    it('waits for a draining connection after query cancellation', function (): void {
        $client = makeClient(enableServerSideCancellation: true);

        $queryPromise = $client->query('SELECT SLEEP(5)');

        $queryPromise->cancel();

        $queryPromise->catch(function (): void {
            // Expected cancellation rejection.
        });

        $shutdownResolved = false;

        await($client->closeAsync()->then(function () use (&$shutdownResolved): void {
            $shutdownResolved = true;
        }));

        expect($shutdownResolved)->toBeTrue();
    });

    it('is safe when closeAsync() and close() are called back-to-back', function (): void {
        $client = makeClient();

        $shutdownResolved = false;

        $shutdownPromise = $client->closeAsync()->then(function () use (&$shutdownResolved): void {
            $shutdownResolved = true;
        });

        // Force-close in the same tick before the event loop has a chance to run.
        $client->close();

        await($shutdownPromise);

        expect($shutdownResolved)->toBeTrue();
    });

    it('renders the client unusable after closeAsync() resolves', function (): void {
        $client = makeClient();

        await($client->closeAsync());

        $threw = false;

        try {
            await($client->query('SELECT 1'));
        } catch (NotInitializedException) {
            $threw = true;
        } catch (PoolException) {
            $threw = true;
        }

        expect($threw)->toBeTrue();
    });

    it('waits for an active transaction to commit before resolving', function (): void {
        $client = makeTransactionClient();

        $txCommitted = false;

        /** @var Promise<bool> $txStarted */
        $txStarted = new Promise();

        $txPromise = $client->transaction(function ($tx) use (&$txCommitted, $txStarted) {
            return async(function () use ($tx, &$txCommitted, $txStarted) {
                // Signal to the outside world that the transaction has successfully
                // borrowed a connection and is now "active"
                $txStarted->resolve(true);

                await($tx->query('SELECT SLEEP(1)'));
                $txCommitted = true;
            });
        });

        // Wait until the transaction actually has the connection!
        await($txStarted);

        // NOW we can safely trigger the graceful shutdown.
        $shutdownPromise = $client->closeAsync();

        await(Promise::all([$txPromise, $shutdownPromise]));

        expect($txCommitted)->toBeTrue();
    });
});
