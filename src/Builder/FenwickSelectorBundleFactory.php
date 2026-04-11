<?php

declare(strict_types=1);

namespace WeightedSample\Builder;

use WeightedSample\Selector\FenwickTreeSelector;
use WeightedSample\SelectorBundleFactoryInterface;

/**
 * Stateless factory that creates a FenwickTreeSelector paired with a FenwickSelectorBuilder.
 *
 * The same FenwickTreeSelector instance is shared between SelectorBundle::$selector
 * and FenwickSelectorBuilder, guaranteeing that subtract() calls are immediately
 * reflected in pick() without any rebuild.
 *
 * This is the default SelectorBundleFactory for BoxPool.
 */
final class FenwickSelectorBundleFactory implements SelectorBundleFactoryInterface
{
    /**
     * @param list<int> $weights
     */
    public function create(array $weights): SelectorBundle
    {
        $selector = FenwickTreeSelector::build($weights);

        return new SelectorBundle(
            selector: $selector,
            builder:  new FenwickSelectorBuilder($selector),  // 同一インスタンスを共有
        );
    }
}
