<?php

declare(strict_types=1);

namespace Hibla\Mysql\Traits;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;

/**
 * Provides helper methods for propagating promise cancellations.
 *
 * @internal
 */
trait CancellationHelperTrait
{
    /**
     * Bridges cancel() → cancelChain() on a public-facing promise.
     *
     * Public methods return the LEAF of a promise chain. When a user calls
     * cancel() on that leaf, it only cancels that node and its children —
     * it never reaches the ROOT where the real onCancel handler (KILL QUERY,
     * connection release) lives.
     *
     * This bridge registers an onCancel hook so that cancel() on the leaf
     * immediately walks up to the root via cancelChain(), triggering all
     * cleanup handlers correctly — including KILL QUERY dispatch in Connection
     * and connection release back to the pool.
     *
     * @template T
     *
     * @param PromiseInterface<T> $promise
     *
     * @return PromiseInterface<T>
     */
    private function withCancellation(PromiseInterface $promise): PromiseInterface
    {
        $promise->onCancel($promise->cancelChain(...));

        return $promise;
    }

    /**
     * Forwards the cancellation signal from the outer chain to the dynamically
     * generated inner promise.
     *
     * @param PromiseInterface<mixed> $outer
     * @param PromiseInterface<mixed>|null &$innerPromise
     */
    private function bindInnerCancellation(PromiseInterface $outer, ?PromiseInterface &$innerPromise): void
    {
        $outer->onCancel(function () use (&$innerPromise): void {
            if ($innerPromise !== null) {
                $innerPromise->cancelChain();
            }
        });
    }

    /**
     * Shields an internal promise from cancellation.
     *
     * Returns a new promise that mirrors the internal promise's outcome.
     * If the returned promise is cancelled by the user, the cancellation
     * DOES NOT propagate to the internal promise. The internal promise will
     * safely run to completion, ensuring finally() blocks execute.
     *
     * @template T
     *
     * @param PromiseInterface<T> $internalPromise
     *
     * @return PromiseInterface<T>
     */
    private function shield(PromiseInterface $internalPromise): PromiseInterface
    {
        /** @var Promise<T> $userPromise */
        $userPromise = new Promise();

        $internalPromise->then(
            static function (mixed $v) use ($userPromise): void {
                if ($userPromise->isPending()) {
                    $userPromise->resolve($v);
                }
            },
            static function (\Throwable $e) use ($userPromise): void {
                if ($userPromise->isPending()) {
                    $userPromise->reject($e);
                }
            }
        );

        return $userPromise;
    }
}
