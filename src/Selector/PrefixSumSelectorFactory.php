<?php

declare(strict_types=1);

namespace WeightedSample\Selector;

use WeightedSample\SelectorFactoryInterface;

/**
 * Stateless factory for PrefixSumSelector.
 *
 * Build: O(n) — constructs prefix sum array.
 * Pick:  O(log n) — binary search on prefix sums.
 * Suitable for small to medium item sets.
 */
final readonly class PrefixSumSelectorFactory implements SelectorFactoryInterface
{
    /**
     * @param list<int> $weights
     */
    public function create(array $weights): SelectorInterface
    {
        return PrefixSumSelector::build($weights);
    }
}
