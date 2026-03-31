<?php

declare(strict_types=1);

namespace WeightedSample\Filter;

/**
 * Determines whether an item should be included in a pool based on its weight.
 *
 * Returns true  → include the item
 * Returns false → silently exclude the item
 * Throws        → propagate the error to the caller
 *
 * @template T
 * @see CountedItemFilterInterface for pools where items have a finite stock count (BoxPool)
 */
interface ItemFilterInterface
{
    /**
     * @param T $item
     */
    public function accepts(mixed $item, int $weight): bool;
}
