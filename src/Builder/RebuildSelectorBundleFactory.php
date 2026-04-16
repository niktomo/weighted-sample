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
final readonly class RebuildSelectorBundleFactory implements SelectorBundleFactoryInterface
{
    public function __construct(
        private readonly SelectorFactoryInterface $selectorFactory = new PrefixSumSelectorFactory(),
    ) {
    }

    /**
     * @param list<int> $weights
     */
    public function create(array $weights): SelectorBuilderInterface
    {
        return new RebuildSelectorBuilder($this->selectorFactory, $weights);
    }
}
