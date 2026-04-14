<?php

declare(strict_types=1);

namespace WeightedSample\Filter;

/**
 * Composes multiple filters with AND logic (all filters must accept).
 *
 * Evaluation is short-circuit: the first filter that returns false stops
 * further evaluation and the item is rejected immediately.
 * Exceptions from inner filters are propagated as-is without wrapping:
 *
 *   $filter = new CompositeFilter([new PositiveValueFilter(), new StrictValueFilter()]);
 *   // If StrictValueFilter::accepts() throws \RangeException, it bubbles out of
 *   // CompositeFilter::accepts() directly.
 *
 * Implements CountedItemFilterInterface so it can be used with BoxPool.
 * For inner filters that do not implement CountedItemFilterInterface,
 * acceptsWithCount() falls back to accepts() — the count is simply ignored.
 *
 * @template T
 * @implements CountedItemFilterInterface<T>
 */
final readonly class CompositeFilter implements CountedItemFilterInterface
{
    /** @param list<ItemFilterInterface<T>> $filters */
    public function __construct(private array $filters)
    {
    }

    public function accepts(mixed $item, int $weight): bool
    {
        foreach ($this->filters as $filter) {
            if (! $filter->accepts($item, $weight)) {
                return false;
            }
        }

        return true;
    }

    public function acceptsWithCount(mixed $item, int $weight, int $count): bool
    {
        foreach ($this->filters as $filter) {
            $result = $filter instanceof CountedItemFilterInterface
                ? $filter->acceptsWithCount($item, $weight, $count)
                : $filter->accepts($item, $weight);

            if (! $result) {
                return false;
            }
        }

        return true;
    }
}
