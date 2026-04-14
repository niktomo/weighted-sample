<?php

declare(strict_types=1);

namespace WeightedSample\Builder;

use InvalidArgumentException;
use LogicException;
use WeightedSample\ItemExclusionObserverInterface;
use WeightedSample\Selector\SelectorInterface;
use WeightedSample\TotalWeightQueryInterface;

/**
 * O(log n) selector builder backed by a FenwickTreeSelector.
 *
 * Uses the Observer pattern: subtract() notifies the selector via
 * ItemExclusionObserverInterface::onItemExcluded(), which updates the
 * internal Fenwick tree in O(log n) without any full rebuild.
 *
 * The constructor accepts any object that satisfies all three interfaces:
 * SelectorInterface (for picking), ItemExclusionObserverInterface (for exclusion
 * notification), and TotalWeightQueryInterface (for total-weight querying).
 * FenwickSelectorBuilder is therefore not coupled to the concrete FenwickTreeSelector class.
 *
 * totalWeight() delegates to TotalWeightQueryInterface::totalWeight(), which is O(1).
 */
final class FenwickSelectorBuilder implements SelectorBuilderInterface
{
    public function __construct(
        private readonly SelectorInterface&ItemExclusionObserverInterface&TotalWeightQueryInterface $selector,
    ) {
    }

    /**
     * Delegates to onItemExcluded(), which calls update($index, 0) on the Fenwick tree.
     * Calling subtract() on an already-excluded index is a no-op (delta = 0).
     *
     * @throws InvalidArgumentException if $index is out of range [0, size)
     */
    public function subtract(int $index): void
    {
        $this->selector->onItemExcluded($index);  // O(log n) via observer
    }

    /**
     * @throws LogicException if totalWeight() === 0
     */
    public function currentSelector(): SelectorInterface
    {
        if ($this->totalWeight() === 0) {
            throw new LogicException(
                'currentSelector() must not be called when totalWeight() === 0.',
            );
        }

        return $this->selector;
    }

    public function totalWeight(): int
    {
        return $this->selector->totalWeight();  // O(1)
    }
}
