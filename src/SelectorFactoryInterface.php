<?php

declare(strict_types=1);

namespace WeightedSample;

use WeightedSample\Selector\SelectorInterface;

/**
 * Stateless factory that builds a SelectorInterface from a weight list.
 *
 * Used by WeightedPool. For exhaustible pools (BoxPool), use SelectorBundleFactoryInterface.
 */
interface SelectorFactoryInterface
{
    /**
     * Build a selector from a list of positive integer weights.
     *
     * @param  list<int> $weights
     * @throws \InvalidArgumentException if weights is empty or contains non-positive values
     * @throws \OverflowException        if total weight exceeds PHP_INT_MAX
     */
    public function create(array $weights): SelectorInterface;
}
