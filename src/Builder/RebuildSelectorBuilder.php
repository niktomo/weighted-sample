<?php

declare(strict_types=1);

namespace WeightedSample\Builder;

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
        $this->current           = $factory->create($weights);
        $this->cachedTotalWeight = array_sum($weights);  // 初期化時のみ O(n)
    }

    public function subtract(int $index): void
    {
        $this->cachedTotalWeight -= $this->weights[$index];  // 差分更新 O(1)
        $this->weights[$index]    = 0;

        $this->rebuild();
    }

    public function currentSelector(): SelectorInterface
    {
        return $this->current;
    }

    public function totalWeight(): int
    {
        return $this->cachedTotalWeight;  // O(1)
    }

    private function rebuild(): void
    {
        /** @var list<int> $activeWeights */
        $activeWeights = [];
        /** @var list<int> $indexMap */
        $indexMap = [];

        foreach ($this->weights as $originalIndex => $weight) {
            if ($weight > 0) {
                $activeWeights[] = $weight;
                $indexMap[]      = $originalIndex;
            }
        }

        if ($activeWeights === []) {
            // 全アイテム消費済み。Pool が totalWeight()==0 を確認してから pick() するため到達しない。
            return;
        }

        $inner         = $this->factory->create($activeWeights);
        $this->current = new MappedSelector($inner, $indexMap); // compact → original index
    }
}
