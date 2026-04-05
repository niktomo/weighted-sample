<?php

declare(strict_types=1);

namespace WeightedSample\Selector;

/**
 * A selector that supports O(log n) in-place weight updates.
 *
 * Use with DestructivePool or BoxPool to avoid O(n) full rebuilds on every draw().
 * FenwickTreeSelector is the canonical implementation.
 *
 * Typical usage:
 *   DestructivePool — update(index, 0) to exclude a drawn item
 *   BoxPool         — update(index, 0) when an item's stock reaches zero
 */
interface UpdatableSelectorInterface extends SelectorInterface
{
    /**
     * Update the weight at $index to $newWeight.
     * Pass 0 to exclude the item from all future picks.
     *
     * @param int $index     0-based index into the weights array
     * @param int $newWeight new weight (≥ 0)
     */
    public function update(int $index, int $newWeight): void;

    /**
     * Sum of all current weights.
     * Returns 0 when all items have been removed or exhausted.
     */
    public function totalWeight(): int;
}
