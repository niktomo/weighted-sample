<?php

declare(strict_types=1);

namespace WeightedSample\Filter;

/**
 * Extends ItemFilterInterface for pools where items have a finite stock count (BoxPool).
 *
 * Implementations receive both the weight and the current stock count,
 * allowing filters to reject items based on either or both values.
 *
 * @template T
 * @extends ItemFilterInterface<T>
 */
interface CountedItemFilterInterface extends ItemFilterInterface
{
    /**
     * @param T $item
     */
    public function acceptsWithCount(mixed $item, int $weight, int $count): bool;
}
