<?php

declare(strict_types=1);

namespace WeightedSample\Builder;

use InvalidArgumentException;
use LogicException;
use OverflowException;
use WeightedSample\Internal\MappedSelector;
use WeightedSample\Selector\SelectorInterface;
use WeightedSample\SelectorFactoryInterface;

/**
 * O(n) selector builder that fully rebuilds the Selector after each subtract().
 *
 * After one or more subtracts the active item set is smaller than the original.
 * The inner Selector is rebuilt from a compact list of remaining weights —
 * its 0-based indices no longer match the original positions in BoxPool::$items.
 *
 * MappedSelector (Internal) bridges this gap:
 *   compact index k → $indexMap[k] → original BoxPool item index.
 * The translation is transparent to callers; currentSelector()->pick() always
 * returns an original index that BoxPool can use directly.
 *
 * Invariant:
 *   - Before the first subtract(): $current is the Selector returned directly
 *     by $factory->create() (no wrapping).
 *   - After any subtract(): $current is always a MappedSelector, because at
 *     least one item has been excluded and compact indices diverge from original.
 *
 * totalWeight() is O(1) via a cached counter updated on each subtract().
 *
 * Use FenwickSelectorBuilder (O(log n)) for large pools.
 * This builder is suitable for small pools or when AliasTableSelectorFactory is required.
 */
final class RebuildSelectorBuilder implements SelectorBuilderInterface
{
    private SelectorInterface $current;

    private int $cachedTotalWeight;

    private bool $dirty = false;

    /** @var array<int, int> original-index → current weight (0 = excluded) */
    private array $weights;

    /**
     * @param list<int> $weights
     */
    public function __construct(
        private readonly SelectorFactoryInterface $factory,
        array $weights,
    ) {
        $this->weights = $weights;
        $this->current = $factory->create($weights);

        // Compute initial total weight with overflow guard — O(n) only at construction.
        $total = 0;
        foreach ($weights as $w) {
            if ($total > PHP_INT_MAX - $w) {
                throw new OverflowException(
                    'Total weight would exceed PHP_INT_MAX; reduce item count or individual weights.',
                );
            }
            $total += $w;
        }
        $this->cachedTotalWeight = $total;
    }

    public function subtract(int $index): void
    {
        if (!array_key_exists($index, $this->weights)) {
            throw new InvalidArgumentException(
                "Index {$index} does not exist in the pool.",
            );
        }

        if ($this->weights[$index] === 0) {
            return;  // already excluded; avoid redundant dirty flag and O(n) rebuild
        }

        $this->cachedTotalWeight -= $this->weights[$index];  // O(1) diff update
        $this->weights[$index]    = 0;
        $this->dirty              = true;
    }

    public function currentSelector(): SelectorInterface
    {
        if ($this->dirty) {
            $this->rebuild();
            $this->dirty = false;
        }

        return $this->current;
    }

    public function totalWeight(): int
    {
        return $this->cachedTotalWeight;  // O(1)
    }

    private function rebuild(): void
    {
        $activeWeights = [];
        $indexMap      = [];

        foreach ($this->weights as $originalIndex => $weight) {
            if ($weight > 0) {
                $activeWeights[] = $weight;
                $indexMap[]      = $originalIndex;
            }
        }

        if ($activeWeights === []) {
            throw new LogicException(
                'Rebuild invariant violated: all weights are zero but currentSelector() was called. '
                . 'Pool must check totalWeight() === 0 before calling currentSelector().',
            );
        }

        $inner         = $this->factory->create($activeWeights);
        $this->current = new MappedSelector($inner, $indexMap); // compact → original index
    }
}
