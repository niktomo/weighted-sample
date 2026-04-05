<?php

declare(strict_types=1);

namespace WeightedSample\Filter;

use InvalidArgumentException;

/**
 * Throws InvalidArgumentException for items with weight ≤ 0.
 * When used with BoxPool, also throws for items with count ≤ 0.
 *
 * @template T
 * @implements CountedItemFilterInterface<T>
 */
final class StrictValueFilter implements CountedItemFilterInterface
{
    public function accepts(mixed $item, int $weight): bool
    {
        if ($weight <= 0) {
            throw new InvalidArgumentException("Each item's weight must be a positive integer, {$weight} given.");
        }

        return true;
    }

    public function acceptsWithCount(mixed $item, int $weight, int $count): bool
    {
        $this->accepts($item, $weight);

        if ($count <= 0) {
            throw new InvalidArgumentException("Each item's count must be a positive integer, {$count} given.");
        }

        return true;
    }
}
