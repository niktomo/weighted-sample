<?php

declare(strict_types=1);

namespace WeightedSample\Pool;

use WeightedSample\Builder\FenwickSelectorBundleFactory;
use WeightedSample\Builder\SelectorBuilderInterface;
use WeightedSample\Exception\AllItemsFilteredException;
use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Filter\CountedItemFilterInterface;
use WeightedSample\Filter\PositiveValueFilter;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Randomizer\SecureRandomizer;
use WeightedSample\SelectorBundleFactoryInterface;

/**
 * Weighted pool where each item has a finite count.
 * Drawing decrements the count; items with count=0 are excluded via the builder.
 *
 * @template T
 * @implements ExhaustiblePoolInterface<T>
 */
final class BoxPool implements ExhaustiblePoolInterface
{
    /** @var list<T> */
    private readonly array $items;

    /** @var list<int> */
    private array $counts;

    private readonly SelectorBuilderInterface $builder;

    /**
     * @param list<T>   $items
     * @param list<int> $counts
     */
    private function __construct(
        array $items,
        array $counts,
        SelectorBuilderInterface $builder,
        private readonly RandomizerInterface $randomizer,
    ) {
        $this->items   = $items;
        $this->counts  = $counts;
        $this->builder = $builder;
    }

    /**
     * @template TItem
     * @param iterable<TItem>                         $items
     * @param \Closure(TItem): int                    $weightExtractor
     * @param \Closure(TItem): int                    $countExtractor
     * @param CountedItemFilterInterface<TItem>       $filter
     * @param SelectorBundleFactoryInterface          $selectorBundleFactory
     * @param RandomizerInterface                     $randomizer
     * @return self<TItem>
     */
    public static function of(
        iterable $items,
        \Closure $weightExtractor,
        \Closure $countExtractor,
        CountedItemFilterInterface $filter = new PositiveValueFilter(),
        SelectorBundleFactoryInterface $selectorBundleFactory = new FenwickSelectorBundleFactory(),
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

        $bundle = $selectorBundleFactory->create($filteredWeights);

        return new self(
            $filteredItems,
            $filteredCounts,
            $bundle->builder,
            $randomizer,
        );
    }

    /**
     * Draws one item and decrements its count.
     * Calls builder->subtract(index) when count reaches zero.
     *
     * Invariant: builder->totalWeight() > 0 iff at least one item has count > 0.
     * This is maintained because subtract(index) is called exactly when count reaches 0,
     * keeping the builder's weight state in sync with $this->counts.
     *
     * @return T
     * @throws EmptyPoolException
     */
    public function draw(): mixed
    {
        if ($this->builder->totalWeight() === 0) {
            throw new EmptyPoolException('The pool is empty.');
        }

        $selectedIndex = $this->builder->currentSelector()->pick($this->randomizer);
        $item          = $this->items[$selectedIndex];
        $newCount      = $this->counts[$selectedIndex] - 1;

        // Intermediate variable required: direct property index-assignment widens
        // list<int> to non-empty-array<int,int> in PHPStan; array_values() re-narrows.
        $counts                 = $this->counts;
        $counts[$selectedIndex] = $newCount;
        $this->counts           = array_values($counts);

        if ($newCount === 0) {
            $this->builder->subtract($selectedIndex);
        }

        return $item;
    }

    /**
     * @return list<T>
     */
    public function drawMany(int $count): array
    {
        if ($count < 0) {
            throw new \InvalidArgumentException('$count must be non-negative.');
        }

        $results = [];

        for ($i = 0; $i < $count && !$this->isEmpty(); $i++) {
            $results[] = $this->draw();
        }

        return $results;
    }

    public function isEmpty(): bool
    {
        return $this->builder->totalWeight() === 0;
    }
}
