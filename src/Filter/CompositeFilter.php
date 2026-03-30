<?php

declare(strict_types=1);

namespace WeightedSample\Filter;

/**
 * Composes multiple filters with AND logic (all filters must accept).
 *
 * Evaluation is short-circuit: the first filter that returns false stops
 * further evaluation and the item is rejected immediately.
 * Exceptions from inner filters are propagated as-is.
 */
final readonly class CompositeFilter implements ItemFilterInterface
{
    /** @param list<ItemFilterInterface> $filters */
    public function __construct(private array $filters)
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
