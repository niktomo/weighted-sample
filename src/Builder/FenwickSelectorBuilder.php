<?php

declare(strict_types=1);

namespace WeightedSample\Builder;

use WeightedSample\Selector\FenwickTreeSelector;
use WeightedSample\Selector\SelectorInterface;

/**
 * O(log n) selector builder backed by a FenwickTreeSelector.
 *
 * subtract() calls update(index, 0) on the underlying FenwickTreeSelector,
 * which propagates the change in O(log n) without any full rebuild.
 *
 * totalWeight() delegates to FenwickTreeSelector::totalWeight(), which is O(1).
 */
final class FenwickSelectorBuilder implements SelectorBuilderInterface
{
    public function __construct(
        private readonly FenwickTreeSelector $selector,
    ) {
    }

    public function subtract(int $index): void
    {
        $this->selector->update($index, 0);  // O(log n)
    }

    public function currentSelector(): SelectorInterface
    {
        return $this->selector;
    }

    public function totalWeight(): int
    {
        return $this->selector->totalWeight();  // O(1)
    }
}
