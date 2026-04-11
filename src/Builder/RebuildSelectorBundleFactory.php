<?php

declare(strict_types=1);

namespace WeightedSample\Builder;

use WeightedSample\Selector\PrefixSumSelectorFactory;
use WeightedSample\SelectorBundleFactoryInterface;
use WeightedSample\SelectorFactoryInterface;

/**
 * Stateless factory that creates a RebuildSelectorBuilder paired with any SelectorFactory.
 *
 * Defaults to PrefixSumSelectorFactory. Inject AliasTableSelectorFactory for
 * WeightedPool-style O(1) picks (though note that Alias rebuild is O(n) per subtract).
 */
final class RebuildSelectorBundleFactory implements SelectorBundleFactoryInterface
{
    public function __construct(
        private readonly SelectorFactoryInterface $selectorFactory = new PrefixSumSelectorFactory(),
    ) {
    }

    /**
     * @param list<int> $weights
     */
    public function create(array $weights): SelectorBundle
    {
        $builder = new RebuildSelectorBuilder($this->selectorFactory, $weights);

        return new SelectorBundle(
            selector: $builder->currentSelector(),  // 初期 Selector（Pool 構築時のみ使用）
            builder:  $builder,
        );
    }
}
