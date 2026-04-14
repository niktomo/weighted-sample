<?php

declare(strict_types=1);

namespace WeightedSample\Builder;

use WeightedSample\Selector\FenwickTreeSelector;
use WeightedSample\SelectorBundleFactoryInterface;

/**
 * Stateless factory that creates a FenwickSelectorBuilder backed by a FenwickTreeSelector.
 *
 * The FenwickTreeSelector instance is held exclusively by the returned FenwickSelectorBuilder.
 * subtract() calls update(index, 0) directly on the shared selector in O(log n),
 * so currentSelector()->pick() immediately reflects all prior subtractions without any rebuild.
 *
 * This is the default SelectorBundleFactory for BoxPool.
 */
final class FenwickSelectorBundleFactory implements SelectorBundleFactoryInterface
{
    /**
     * @param list<int> $weights
     */
    public function create(array $weights): SelectorBuilderInterface
    {
        return new FenwickSelectorBuilder(FenwickTreeSelector::build($weights));
    }
}
