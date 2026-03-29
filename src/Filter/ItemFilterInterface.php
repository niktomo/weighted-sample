<?php

declare(strict_types=1);

namespace WeightedSample\Filter;

/**
 * Determines whether an item should be included in a pool.
 *
 * Returns true  → include the item
 * Returns false → silently exclude the item
 * Throws        → propagate the error to the caller
 */
interface ItemFilterInterface
{
    /**
     * @param mixed $item
     */
    public function accepts(mixed $item, int $weight, ?int $count): bool;
}
