<?php

declare(strict_types=1);

namespace WeightedSample\Pool;

use WeightedSample\Exception\AllItemsFilteredException;
use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Filter\ItemFilterInterface;
use WeightedSample\Filter\PositiveValueFilter;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Randomizer\SecureRandomizer;
use WeightedSample\Selector\PrefixSumSelector;
use WeightedSample\Selector\SelectorInterface;
use WeightedSample\Selector\UpdatableSelectorInterface;

/**
 * Weighted pool that removes each drawn item.
 *
 * Performance note:
 *   With PrefixSumSelector (default): each draw() rebuilds the selector in O(n),
 *   total cost O(n²). Suitable for small to medium pools.
 *   With FenwickTreeSelector: each draw() calls update() in O(log n),
 *   total cost O(n log n). Recommended for large pools (n ≫ 1,000).
 *
 * @template T
 * @implements ExhaustiblePoolInterface<T>
 */
final class DestructivePool implements ExhaustiblePoolInterface
{
    /** @var list<T> */
    private array $items;

    /** @var list<int> */
    private array $weights;

    private ?SelectorInterface $selector;

    /** @var class-string<SelectorInterface> */
    private readonly string $selectorClass;

    /**
     * @param list<T>                        $items
     * @param list<int>                      $weights  pre-computed weights parallel to $items
     * @param class-string<SelectorInterface> $selectorClass
     */
    private function __construct(
        array $items,
        array $weights,
        private readonly RandomizerInterface $randomizer,
        string $selectorClass,
    ) {
        $this->items         = $items;
        $this->weights       = $weights;
        $this->selectorClass = $selectorClass;
        $this->selector      = $items !== [] ? $selectorClass::build($weights) : null;
    }

    /**
     * @template TItem
     * @param iterable<TItem>                    $items
     * @param \Closure(TItem): int               $weightExtractor
     * @param ItemFilterInterface<TItem>          $filter
     * @param class-string<SelectorInterface>     $selectorClass
     * @param RandomizerInterface                 $randomizer
     * @return self<TItem>
     */
    public static function of(
        iterable $items,
        \Closure $weightExtractor,
        ItemFilterInterface $filter = new PositiveValueFilter(),
        string $selectorClass = PrefixSumSelector::class,
        RandomizerInterface $randomizer = new SecureRandomizer(),
    ): self {
        /** @var list<TItem> $filteredItems */
        $filteredItems = [];
        /** @var list<int> $filteredWeights */
        $filteredWeights = [];
        foreach ($items as $item) {
            $weight = $weightExtractor($item);
            if ($filter->accepts($item, $weight)) {
                $filteredItems[]   = $item;
                $filteredWeights[] = $weight;
            }
        }

        if ($filteredItems === []) {
            throw new AllItemsFilteredException('Cannot create a DestructivePool: no items remain after filtering.');
        }

        return new self(
            $filteredItems,
            $filteredWeights,
            $randomizer,
            $selectorClass,
        );
    }

    /**
     * Draws one item and removes it from the pool.
     *
     * When the selector implements UpdatableSelectorInterface (e.g. FenwickTreeSelector),
     * removal is O(log n) via update(index, 0) — no rebuild required.
     * Otherwise, the selector is fully rebuilt in O(n) after each draw.
     *
     * @return T
     * @throws EmptyPoolException
     */
    public function draw(): mixed
    {
        if ($this->selector instanceof UpdatableSelectorInterface) {
            // O(log n) update path — items array is kept intact, no rebuild needed
            if ($this->selector->totalWeight() === 0) {
                throw new EmptyPoolException('The pool is empty.');
            }

            $selectedIndex = $this->selector->pick($this->randomizer);
            $item          = $this->items[$selectedIndex];
            $this->selector->update($selectedIndex, 0);

            return $item;
        }

        // O(n) rebuild path (PrefixSumSelector default)
        if ($this->selector === null) {
            throw new EmptyPoolException('The pool is empty.');
        }

        $selectedIndex = $this->selector->pick($this->randomizer);
        $item          = $this->items[$selectedIndex];

        array_splice($this->items, $selectedIndex, 1);
        array_splice($this->weights, $selectedIndex, 1);

        $this->selector = $this->items !== [] ? ($this->selectorClass)::build($this->weights) : null;

        return $item;
    }

    public function isEmpty(): bool
    {
        if ($this->selector instanceof UpdatableSelectorInterface) {
            return $this->selector->totalWeight() === 0;
        }

        return $this->items === [];
    }
}
