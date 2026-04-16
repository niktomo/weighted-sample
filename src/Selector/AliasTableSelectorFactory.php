<?php

declare(strict_types=1);

namespace WeightedSample\Selector;

use WeightedSample\SelectorFactoryInterface;

/**
 * Stateless factory for AliasTableSelector.
 *
 * Build: O(n) — constructs alias table.
 * Pick:  O(1) — single random call, pure integer arithmetic.
 * Recommended for WeightedPool with large item sets (≥ ~50) and frequent draws.
 */
final readonly class AliasTableSelectorFactory implements SelectorFactoryInterface
{
    /**
     * @param list<int> $weights
     */
    public function create(array $weights): SelectorInterface
    {
        return AliasTableSelector::build($weights);
    }
}
