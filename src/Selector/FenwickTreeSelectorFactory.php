<?php

declare(strict_types=1);

namespace WeightedSample\Selector;

use WeightedSample\SelectorFactoryInterface;

/**
 * Stateless factory for FenwickTreeSelector.
 *
 * Build:  O(n) — constructs Fenwick tree.
 * Pick:   O(log n) — tree descent.
 * Update: O(log n) — point update via SelectorBuilderInterface.
 * Recommended for BoxPool (default).
 */
final readonly class FenwickTreeSelectorFactory implements SelectorFactoryInterface
{
    /**
     * @param list<int> $weights
     */
    public function create(array $weights): SelectorInterface
    {
        return FenwickTreeSelector::build($weights);
    }
}
