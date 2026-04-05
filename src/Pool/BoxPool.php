<?php

declare(strict_types=1);

namespace WeightedSample\Pool;

use WeightedSample\Exception\AllItemsFilteredException;
use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Filter\CountedItemFilterInterface;
use WeightedSample\Filter\PositiveValueFilter;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Randomizer\SecureRandomizer;
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
     * @param iterable<TItem>                         $items
     * @param \Closure(TItem): int                    $weightExtractor
     * @param \Closure(TItem): int                    $countExtractor
     * @param CountedItemFilterInterface<TItem>       $filter
     * @param class-string<SelectorInterface>         $selectorClass
     * @param RandomizerInterface                     $randomizer
     * @return self<TItem>
     */
    public static function of(
        iterable $items,
        \Closure $weightExtractor,
        \Closure $countExtractor,
        CountedItemFilterInterface $filter = new PositiveValueFilter(),
        string $selectorClass = PrefixSumSelector::class,
        RandomizerInterface $randomizer = new SecureRandomizer(),
    ): self {
        /** @var list<TItem> $filteredItems */
        $filteredItems = [];
        /** @var list<int> $filteredWeights */
        $filteredWeights = [];
        /** @var list<int> $filteredCounts */
        $filteredCounts = [];
        foreach ($items as $item) {
            $weight = $weightExtractor($item);
            $count  = $countExtractor($item);
            if ($filter->acceptsWithCount($item, $weight, $count)) {
                $filteredItems[]   = $item;
                $filteredWeights[] = $weight;
                $filteredCounts[]  = $count;
            }
        }

        if ($filteredItems === []) {
            throw new AllItemsFilteredException('Cannot create a BoxPool: no items remain after filtering.');
        }

        return new self(
            $filteredItems,
            $filteredWeights,
            $filteredCounts,
            $randomizer,
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
            // Item fully exhausted: weights change, so rebuild the selector.
            array_splice($this->items, $selectedIndex, 1);
            array_splice($this->weights, $selectedIndex, 1);
            array_splice($this->counts, $selectedIndex, 1);
            $this->selector = $this->items !== [] ? ($this->selectorClass)::build($this->weights) : null;
        } else {
            // Count decremented but item still available: weights unchanged, reuse selector.
            // Intermediate variable required: direct property index-assignment widens
            // list<int> to non-empty-array<int,int> in PHPStan; array_values() re-narrows.
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
