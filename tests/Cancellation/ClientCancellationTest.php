<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Exceptions\CancelledException;

use function Hibla\await;
use function Hibla\delay;

describe('Client Query Cancellation', function (): void {
    it('cancels a non-prepared query via the client and throws CancelledException', function (): void {
        $client = makeClient(enableServerSideCancellation: true);
        $startTime = microtime(true);

        $queryPromise = $client->query('SELECT SLEEP(10) AS result');

        Loop::addTimer(0.1, function () use ($queryPromise): void {
            $queryPromise->cancel();
        });

        expect(fn () => await($queryPromise))
            ->toThrow(CancelledException::class)
        ;

        expect(microtime(true) - $startTime)->toBeLessThan(1.0);

        // ALWAYS await closeAsync in tests to prevent hanging loops
        await($client->closeAsync());
    });

    test('pool connection is healthy after a cancelled non-prepared query', function (): void {
        $client = makeClient(enableServerSideCancellation: true);

        $queryPromise = $client->query('SELECT SLEEP(10) AS result');

        Loop::addTimer(0.1, function () use ($queryPromise): void {
            $queryPromise->cancel();
        });

        try {
            await($queryPromise);
        } catch (CancelledException) {
        }

        // Small delay to let the side-channel kill and pool absorption finish
        await(delay(0.1));

        $result = await($client->query('SELECT "Alive" AS status'));
        expect($result->fetchOne()['status'])->toBe('Alive');

        await($client->closeAsync());
    });

    it('cancels a prepared query via the client and throws CancelledException', function (): void {
        $client = makeClient(enableServerSideCancellation: true);
        $startTime = microtime(true);

        $queryPromise = $client->query('SELECT SLEEP(?) AS result', [10]);

        Loop::addTimer(0.1, function () use ($queryPromise): void {
            $queryPromise->cancel();
        });

        expect(fn () => await($queryPromise))
            ->toThrow(CancelledException::class)
        ;

        expect(microtime(true) - $startTime)->toBeLessThan(1.0);

        await($client->closeAsync());
    });

    test('pool connection is healthy after a cancelled prepared query', function (): void {
        $client = makeClient(enableServerSideCancellation: true);

        $queryPromise = $client->query('SELECT SLEEP(?) AS result', [10]);

        Loop::addTimer(0.1, function () use ($queryPromise): void {
            $queryPromise->cancel();
        });

        try {
            await($queryPromise);
        } catch (CancelledException) {
        }

        await(delay(0.1));

        $result = await($client->query('SELECT ? AS echo_value', ['HelloAfterCancel']));
        expect($result->fetchOne()['echo_value'])->toBe('HelloAfterCancel');

        await($client->closeAsync());
    });
});

describe('Client Waiter Cancellation', function (): void {
    it('cancels a queued waiter before it reaches the server and throws CancelledException', function (): void {
        // Reduced maxConnections to 2 to speed up setup/teardown of the test
        $client = makeClient(maxConnections: 2, enableServerSideCancellation: true);

        $holders = [
            $client->query('SELECT SLEEP(10)'),
            $client->query('SELECT SLEEP(10)'),
        ];

        $waiterPromise = $client->query('SELECT "ShouldNotRun" AS v');

        Loop::addTimer(0.1, function () use ($waiterPromise): void {
            $waiterPromise->cancel();
        });

        expect(fn () => await($waiterPromise))
            ->toThrow(CancelledException::class)
        ;

        foreach ($holders as $holder) {
            $holder->cancel();
        }

        // Wait just enough for the KILL QUERY packets to be sent
        await(delay(0.2));
        await($client->closeAsync());
    });

    test('pool remains functional after a cancelled waiter', function (): void {
        $client = makeClient(maxConnections: 2, enableServerSideCancellation: true);

        $holders = [
            $client->query('SELECT SLEEP(10)'),
            $client->query('SELECT SLEEP(10)'),
        ];

        $waiterPromise = $client->query('SELECT "ShouldNotRun" AS v');

        Loop::addTimer(0.1, function () use ($waiterPromise): void {
            $waiterPromise->cancel();
        });

        try {
            await($waiterPromise);
        } catch (CancelledException) {
        }

        foreach ($holders as $holder) {
            $holder->cancel();
        }

        await(delay(0.3));

        $result = await($client->query('SELECT "PoolOk" AS status'));
        expect($result->fetchOne()['status'])->toBe('PoolOk');

        await($client->closeAsync());
    });

    test('pool stats show no draining connections after waiter cancellation and drain', function (): void {
        $client = makeClient(maxConnections: 2, enableServerSideCancellation: true);

        $holders = [
            $client->query('SELECT SLEEP(10)'),
            $client->query('SELECT SLEEP(10)'),
        ];

        $waiterPromise = $client->query('SELECT "ShouldNotRun" AS v');

        Loop::addTimer(0.1, function () use ($waiterPromise): void {
            $waiterPromise->cancel();
        });

        try {
            await($waiterPromise);
        } catch (CancelledException) {
        }

        foreach ($holders as $holder) {
            $holder->cancel();
        }

        await(delay(0.4));

        $stats = $client->stats;

        expect($stats['draining_connections'])->toBe(0)
            ->and($stats['waiting_requests'])->toBe(0)
        ;

        await($client->closeAsync());
    });
});
