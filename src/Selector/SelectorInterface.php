<?php

declare(strict_types=1);

namespace WeightedSample\Selector;

use WeightedSample\Randomizer\RandomizerInterface;

/**
 * Weighted index selector.
 *
 * Three implementations are provided:
 *
 *   PrefixSumSelector  — O(n) build, O(log n) pick. Good default.
 *   AliasTableSelector — O(n) build, O(1) pick. Prefer for large immutable pools.
 *   FenwickTreeSelector — O(n) build, O(log n) pick. Supports in-place weight updates; prefer for BoxPool via FenwickSelectorBundleFactory.
 *
 * Construct via SelectorFactoryInterface (WeightedPool) or
 * SelectorBundleFactoryInterface (BoxPool) — do not call build() directly.
 */
interface SelectorInterface
{
    /**
     * Pick one index from [0, n) using the given randomizer.
     */
    public function pick(RandomizerInterface $randomizer): int;
}
