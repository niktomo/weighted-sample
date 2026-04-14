<?php

declare(strict_types=1);

namespace WeightedSample;

/**
 * Observer interface for item exclusion events.
 *
 * Implementors react when an item at a given index is excluded from the selection pool
 * (e.g. its stock reaches zero in BoxPool). This is the Observer side of the pattern:
 * the pool infrastructure acts as the Subject; implementors update their internal
 * state when notified.
 *
 * FenwickTreeSelector implements this interface so that FenwickSelectorBuilder can
 * trigger weight updates without depending on the concrete FenwickTreeSelector class.
 *
 * Total-weight querying is intentionally separated into TotalWeightQueryInterface (ISP).
 *
 * @see TotalWeightQueryInterface for the companion read-only query interface
 */
interface ItemExclusionObserverInterface
{
    /**
     * Called when the item at $index should be removed from selection.
     * The implementor must ensure the item can no longer be picked after this call.
     */
    public function onItemExcluded(int $index): void;
}
