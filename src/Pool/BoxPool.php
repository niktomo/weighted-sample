<?php

declare(strict_types=1);

namespace WeightedSample\Pool;

use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Filter\ItemFilterInterface;
use WeightedSample\Filter\PositiveValueFilter;
use WeightedSample\Internal\PrefixSumIndex;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Randomizer\SeededRandomizer;

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

    private ?PrefixSumIndex $index;

    /**
     * @param list<T>   $items
     * @param list<int> $weights
     * @param list<int> $counts
     */
    private function __construct(
        array $items,
        array $weights,
        array $counts,
        private readonly RandomizerInterface $randomizer,
    ) {
        $this->items   = $items;
        $this->weights = $weights;
        $this->counts  = $counts;
        $this->index   = $items !== [] ? new PrefixSumIndex($weights) : null;
    }

    /**
     * @template TItem
     * @param list<TItem>          $items
     * @param \Closure(TItem): int $weightFn
     * @param \Closure(TItem): int $countFn
     * @return self<TItem>
     */
    public static function of(
        array $items,
        \Closure $weightFn,
        \Closure $countFn,
        ?ItemFilterInterface $filter = null,
        ?RandomizerInterface $randomizer = null,
    ): self {
        $filter ??= new PositiveValueFilter();

        /** @var list<TItem> $filtered */
        $filtered = array_values(array_filter(
            $items,
            fn ($item) => $filter->accepts($item, $weightFn($item), $countFn($item)),
        ));

        if ($filtered === []) {
            throw new EmptyPoolException('Cannot create a BoxPool: no items remain after filtering.');
        }

        return new self(
            $filtered,
            array_map($weightFn, $filtered),
            array_map($countFn, $filtered),
            $randomizer ?? new SeededRandomizer(),
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
        if ($this->index === null) {
            throw new EmptyPoolException('The pool is empty.');
        }

        $randomValue   = $this->randomizer->next($this->index->total());
        $selectedIndex = $this->index->pick($randomValue);
        $item          = $this->items[$selectedIndex];
        $newCount      = $this->counts[$selectedIndex] - 1;

        if ($newCount === 0) {
            array_splice($this->items, $selectedIndex, 1);
            array_splice($this->weights, $selectedIndex, 1);
            array_splice($this->counts, $selectedIndex, 1);
        } else {
            $counts                = $this->counts;
            $counts[$selectedIndex] = $newCount;
            $this->counts          = array_values($counts);
        }

        $this->index = $this->items !== [] ? new PrefixSumIndex($this->weights) : null;

        return $item;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
