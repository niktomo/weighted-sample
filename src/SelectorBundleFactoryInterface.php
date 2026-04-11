<?php

declare(strict_types=1);

namespace WeightedSample;

use WeightedSample\Builder\SelectorBundle;

/**
 * Stateless factory that builds a paired SelectorBundle for exhaustible pools.
 *
 * Used by BoxPool. For immutable pools (WeightedPool), use SelectorFactoryInterface.
 *
 * Implementations must guarantee that SelectorBundle::$selector and
 * SelectorBundle::$builder share the same underlying state so that
 * builder->subtract() is immediately reflected in selector->pick().
 */
interface SelectorBundleFactoryInterface
{
    /**
     * @param  list<int> $weights
     * @throws \InvalidArgumentException if weights is empty or contains non-positive values
     * @throws \OverflowException        if total weight exceeds PHP_INT_MAX
     */
    public function create(array $weights): SelectorBundle;
}
