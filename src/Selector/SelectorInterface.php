<?php

declare(strict_types=1);

namespace WeightedSample\Selector;

use WeightedSample\Randomizer\RandomizerInterface;

/**
 * Weighted index selector.
 *
 * Two implementations are provided:
 *
 *   PrefixSumSelector  — default. O(n) build, O(log n) pick. Integer-only.
 *                        Good default for small to medium item sets.
 *
 *   AliasTableSelector — O(n) build, O(1) pick. Integer pick (float only during build).
 *                        Prefer for large item sets (≥ ~50) with frequent draws.
 *
 * Inject via the `selectorClass` parameter on WeightedPool::of(),
 * DestructivePool::of(), or BoxPool::of().
 */
interface SelectorInterface
{
    /**
     * Build a selector from a list of positive integer weights.
     *
     * @param list<int> $weights
     */
    public static function build(array $weights): static;

    /**
     * Pick one index from [0, n) using the given randomizer.
     */
    public function pick(RandomizerInterface $randomizer): int;
}
