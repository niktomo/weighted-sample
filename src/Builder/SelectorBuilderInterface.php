<?php

declare(strict_types=1);

namespace WeightedSample\Builder;

use InvalidArgumentException;
use LogicException;
use WeightedSample\Selector\SelectorInterface;

/**
 * Manages the mutable selection state of an exhaustible pool (e.g. BoxPool).
 *
 * Responsibilities:
 *   - Pool decides *when* to subtract (BoxPool calls subtract() when item stock hits 0).
 *   - Builder decides *how* to update the underlying Selector.
 *
 * Two concrete implementations ship with this package:
 *   - FenwickSelectorBuilder  — O(log n) in-place point update via FenwickTreeSelector.
 *     Best for BoxPool: no full rebuild on each exclusion.
 *   - RebuildSelectorBuilder  — O(n) full rebuild from a filtered weight list.
 *     Best for pools backed by AliasTableSelector / PrefixSumSelector (immutable selectors).
 *
 * Typical lifecycle:
 * ```php
 * $builder = $factory->create($weights);      // build initial selector
 * while ($builder->totalWeight() > 0) {
 *     $index = $builder->currentSelector()->pick($randomizer);
 *     // ... use $index ...
 *     if ($stockIsEmpty[$index]) {
 *         $builder->subtract($index);          // exclude exhausted item
 *     }
 * }
 * ```
 */
interface SelectorBuilderInterface
{
    /**
     * Exclude the item at $index from all future picks.
     *
     * Must be called at most once per index with weight > 0.
     * Calling subtract() on an already-excluded index is a no-op (weight stays 0).
     *
     * FenwickSelectorBuilder: O(log n) point update.
     * RebuildSelectorBuilder: O(n) full rebuild.
     *
     * @throws InvalidArgumentException if $index is out of range [0, size)
     */
    public function subtract(int $index): void;

    /**
     * Returns the current Selector reflecting all prior subtractions.
     *
     * Must not be called when totalWeight() === 0.
     *
     * @throws LogicException if called with all weights at zero (RebuildSelectorBuilder).
     */
    public function currentSelector(): SelectorInterface;

    /**
     * Sum of remaining (non-excluded) weights.
     * Returns 0 when all items have been excluded.
     * Runs in O(1).
     */
    public function totalWeight(): int;
}
