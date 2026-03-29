<?php

declare(strict_types=1);

namespace WeightedSample\Filter;

/**
 * Composes multiple filters. Returns false on first rejection (short-circuit).
 * Exceptions from inner filters are propagated as-is.
 */
final class CompositeFilter implements ItemFilterInterface
{
    /** @param list<ItemFilterInterface> $filters */
    public function __construct(private readonly array $filters)
    {
    }

    public function accepts(mixed $item, int $weight, ?int $count): bool
    {
        foreach ($this->filters as $filter) {
            if (! $filter->accepts($item, $weight, $count)) {
                return false;
            }
        }

        return true;
    }
}
