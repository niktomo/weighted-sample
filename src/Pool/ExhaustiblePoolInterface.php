<?php

declare(strict_types=1);

namespace WeightedSample\Pool;

/**
 * A pool that can be exhausted. draw() removes or consumes items over time.
 *
 * @template T
 * @extends PoolInterface<T>
 */
interface ExhaustiblePoolInterface extends PoolInterface
{
    /**
     * Returns true when no more items can be drawn.
     */
    public function isEmpty(): bool;
}
