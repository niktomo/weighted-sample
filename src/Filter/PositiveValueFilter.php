<?php

declare(strict_types=1);

namespace WeightedSample\Filter;

/**
 * Silently excludes items with weight ≤ 0 or count ≤ 0.
 */
final class PositiveValueFilter implements ItemFilterInterface
{
    public function accepts(mixed $item, int $weight, ?int $count): bool
    {
        return $weight > 0 && ($count === null || $count > 0);
    }
}
