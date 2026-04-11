<?php

declare(strict_types=1);

namespace WeightedSample\Builder;

use WeightedSample\Selector\SelectorInterface;

/**
 * Manages the mutable state of a Selector for exhaustible pools.
 *
 * Pool's responsibility: decide *when* to subtract (every draw for DestructivePool,
 *                        on count=0 for BoxPool).
 * Builder's responsibility: decide *how* to update the Selector.
 *
 * FenwickSelectorBuilder: O(log n) in-place update.
 * RebuildSelectorBuilder: O(n) full rebuild from modified weights.
 */
interface SelectorBuilderInterface
{
    /**
     * Subtract the item at $index from the selection pool.
     * Updates the internal Selector state accordingly.
     *
     * FenwickSelectorBuilder: O(log n) point update.
     * RebuildSelectorBuilder: O(n) full rebuild.
     */
    public function subtract(int $index): void;

    /**
     * Returns the current Selector reflecting all prior subtractions.
     */
    public function currentSelector(): SelectorInterface;

    /**
     * Sum of remaining weights. Returns 0 when all items are exhausted.
     * Must run in O(1).
     */
    public function totalWeight(): int;
}
