<?php

declare(strict_types=1);

namespace WeightedSample\Filter;

/**
 * Silently excludes items with weight ≤ 0.
 * When used with BoxPool, also excludes items with count ≤ 0.
 *
 * @template T
 * @implements CountedItemFilterInterface<T>
 */
final class PositiveValueFilter implements CountedItemFilterInterface
{
    public function accepts(mixed $item, int $weight): bool
    {
        return $weight > 0;
    }

    public function acceptsWithCount(mixed $item, int $weight, int $count): bool
    {
        return $weight > 0 && $count > 0;
    }
}
