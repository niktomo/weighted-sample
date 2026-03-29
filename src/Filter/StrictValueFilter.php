<?php

declare(strict_types=1);

namespace WeightedSample\Filter;

/**
 * Throws InvalidArgumentException for items with weight ≤ 0 or count ≤ 0.
 */
final class StrictValueFilter implements ItemFilterInterface
{
    public function accepts(mixed $item, int $weight, ?int $count): bool
    {
        if ($weight <= 0) {
            throw new \InvalidArgumentException("Each item's weight must be a positive integer, {$weight} given.");
        }

        if ($count !== null && $count <= 0) {
            throw new \InvalidArgumentException("Each item's count must be a positive integer, {$count} given.");
        }

        return true;
    }
}
