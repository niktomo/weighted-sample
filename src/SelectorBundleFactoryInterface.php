<?php

declare(strict_types=1);

namespace WeightedSample;

use InvalidArgumentException;
use OverflowException;
use WeightedSample\Builder\SelectorBuilderInterface;

/**
 * Stateless factory that builds a SelectorBuilderInterface for exhaustible pools.
 *
 * Used by BoxPool. For immutable pools (WeightedPool), use SelectorFactoryInterface.
 *
 * Implementations must guarantee that the returned builder immediately reflects
 * any subtract() call in its currentSelector()->pick() results.
 */
interface SelectorBundleFactoryInterface
{
    /**
     * @param  list<int> $weights
     * @throws InvalidArgumentException if weights is empty or contains non-positive values
     * @throws OverflowException        if total weight W exceeds PHP_INT_MAX
     *                                   (FenwickSelectorBundleFactory: W > PHP_INT_MAX;
     *                                    Alias-based factories additionally require (n+1)×W ≤ PHP_INT_MAX)
     */
    public function create(array $weights): SelectorBuilderInterface;
}
