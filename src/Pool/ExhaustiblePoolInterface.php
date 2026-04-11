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

    /**
     * Draws up to $count items from the pool.
     * Stops early without throwing if the pool is exhausted before $count is reached.
     * Returns an empty list when $count is 0 or the pool is already empty.
     *
     * @return list<T>
     * @throws \InvalidArgumentException if $count is negative
     */
    public function drawMany(int $count): array;
}
