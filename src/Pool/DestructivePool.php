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
 * Weighted pool that removes each drawn item.
 *
 * Performance note:
 *   Each draw() removes one item and rebuilds the selector in O(n).
 *   Total cost over all draws is O(n²). For pools with hundreds of items
 *   this is negligible; for very large pools (n ≫ 1,000) consider whether
 *   WeightedPool with a pre-filtered list is more appropriate.
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
     * @param iterable<TItem>                $items
     * @param \Closure(TItem): int           $weightFn
     * @param class-string<SelectorInterface> $selectorClass
     * @return self<TItem>
     */
    public static function of(
        iterable $items,
        \Closure $weightFn,
        ?ItemFilterInterface $filter = null,
        ?RandomizerInterface $randomizer = null,
        string $selectorClass = PrefixSumSelector::class,
    ): self {
        $filter ??= new PositiveValueFilter();

        /** @var list<TItem> $filtered */
        $filtered = [];
        foreach ($items as $item) {
            if ($filter->accepts($item, $weightFn($item), null)) {
                $filtered[] = $item;
            }
        }

        if ($filtered === []) {
            throw new EmptyPoolException('Cannot create a DestructivePool: no items remain after filtering.');
        }

        return new self(
            $filtered,
            array_map($weightFn, $filtered),
            $randomizer ?? new SeededRandomizer(),
            $selectorClass,
        );
    }

    /**
     * Draws one item and removes it from the pool.
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

        array_splice($this->items, $selectedIndex, 1);
        array_splice($this->weights, $selectedIndex, 1);

        $this->selector = $this->items !== [] ? ($this->selectorClass)::build($this->weights) : null;

        return $item;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
