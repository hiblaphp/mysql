<?php

declare(strict_types=1);

namespace Hibla\Mysql\Traits;

use Hibla\Promise\Interfaces\PromiseInterface;

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
}
