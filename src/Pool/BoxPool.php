<?php

declare(strict_types=1);

namespace WeightedSample\Pool;

use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Filter\ItemFilterInterface;
use WeightedSample\Filter\PositiveValueFilter;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\PrefixSumSelector;
use WeightedSample\Selector\SelectorInterface;

/**
 * Weighted pool where each item has a finite count.
 * Drawing decrements the count; items with count=0 are excluded.
 *
 * @template T
 * @implements ExhaustiblePoolInterface<T>
 */
final class BoxPool implements ExhaustiblePoolInterface
{
    /** @var list<T> */
    private array $items;

    /** @var list<int> */
    private array $weights;

    /** @var list<int> */
    private array $counts;

    private ?SelectorInterface $selector;

    /** @var class-string<SelectorInterface> */
    private readonly string $selectorClass;

    /**
     * @param list<T>                        $items
     * @param list<int>                      $weights
     * @param list<int>                      $counts
     * @param class-string<SelectorInterface> $selectorClass
     */
    private function __construct(
        array $items,
        array $weights,
        array $counts,
        private readonly RandomizerInterface $randomizer,
        string $selectorClass,
    ) {
        $this->items         = $items;
        $this->weights       = $weights;
        $this->counts        = $counts;
        $this->selectorClass = $selectorClass;
        $this->selector      = $items !== [] ? $selectorClass::build($weights) : null;
    }

    /**
     * @template TItem
     * @param iterable<TItem>                $items
     * @param \Closure(TItem): int           $weightFn
     * @param \Closure(TItem): int           $countFn
     * @param class-string<SelectorInterface> $selectorClass
     * @return self<TItem>
     */
    public static function of(
        iterable $items,
        \Closure $weightFn,
        \Closure $countFn,
        ?ItemFilterInterface $filter = null,
        ?RandomizerInterface $randomizer = null,
        string $selectorClass = PrefixSumSelector::class,
    ): self {
        $filter ??= new PositiveValueFilter();

        /** @var list<TItem> $filtered */
        $filtered = [];
        foreach ($items as $item) {
            if ($filter->accepts($item, $weightFn($item), $countFn($item))) {
                $filtered[] = $item;
            }
        }

        if ($filtered === []) {
            throw new EmptyPoolException('Cannot create a BoxPool: no items remain after filtering.');
        }

        return new self(
            $filtered,
            array_map($weightFn, $filtered),
            array_map($countFn, $filtered),
            $randomizer ?? new SeededRandomizer(),
            $selectorClass,
        );
    }

    /**
     * Draws one item and decrements its count.
     * Removes the item when count reaches zero.
     *
     * @return T
     * @throws EmptyPoolException
     */
    public function draw(): mixed
    {
        if ($this->selector === null) {
            throw new EmptyPoolException('The pool is empty.');
        }

        $selectedIndex = $this->selector->pick($this->randomizer);
        $item          = $this->items[$selectedIndex];
        $newCount      = $this->counts[$selectedIndex] - 1;

        if ($newCount === 0) {
            // アイテムが除外されるときのみ weights が変わるので selector を再構築する
            array_splice($this->items, $selectedIndex, 1);
            array_splice($this->weights, $selectedIndex, 1);
            array_splice($this->counts, $selectedIndex, 1);
            $this->selector = $this->items !== [] ? ($this->selectorClass)::build($this->weights) : null;
        } else {
            // count が減るだけで weights は変わらないので selector はそのまま使い続ける
            $counts                 = $this->counts;
            $counts[$selectedIndex] = $newCount;
            $this->counts           = array_values($counts);
        }

        return $item;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
