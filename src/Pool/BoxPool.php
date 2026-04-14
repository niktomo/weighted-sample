<?php

declare(strict_types=1);

namespace WeightedSample\Pool;

use Closure;
use InvalidArgumentException;
use WeightedSample\Builder\FenwickSelectorBundleFactory;
use WeightedSample\Builder\SelectorBuilderInterface;
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
    /** @var array<int, int> */
    private array $counts;

    /**
     * @param list<T>   $items
     * @param list<int> $counts
     */
    private function __construct(
        private readonly array $items,
        array $counts,
        private readonly SelectorBuilderInterface $builder,
        private readonly RandomizerInterface $randomizer,
    ) {
        $this->counts = $counts;
    }

    /**
     * @template TItem
     * @param iterable<TItem>                         $items
     * @param Closure(TItem): int                     $weightExtractor
     * @param Closure(TItem): int                     $countExtractor
     * @param CountedItemFilterInterface<TItem>       $filter
     * @param SelectorBundleFactoryInterface          $bundleFactory
     * @param RandomizerInterface                     $randomizer
     * @return self<TItem>
     * @throws InvalidArgumentException if no items remain after filtering
     */
    public static function of(
        iterable $items,
        Closure $weightExtractor,
        Closure $countExtractor,
        CountedItemFilterInterface $filter = new PositiveValueFilter(),
        SelectorBundleFactoryInterface $bundleFactory = new FenwickSelectorBundleFactory(),
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
            throw new InvalidArgumentException('Cannot create a BoxPool: no items remain after filtering.');
        }

        return new self(
            $filteredItems,
            $filteredCounts,
            $bundleFactory->create($filteredWeights),
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

        $this->counts[$selectedIndex] = $newCount;

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
            throw new InvalidArgumentException('$count must be non-negative.');
        }

        $results = [];

        while (!$this->isEmpty() && count($results) < $count) {
            $results[] = $this->draw();
        }

        return $results;
    }

    public function isEmpty(): bool
    {
        return $this->builder->totalWeight() === 0;
    }
}
