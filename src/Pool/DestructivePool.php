<?php

declare(strict_types=1);

namespace WeightedSample\Pool;

use WeightedSample\Exception\AllItemsFilteredException;
use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Filter\ItemFilterInterface;
use WeightedSample\Filter\PositiveValueFilter;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Randomizer\SecureRandomizer;
use WeightedSample\Selector\PrefixSumSelectorFactory;
use WeightedSample\Selector\SelectorInterface;
use WeightedSample\SelectorFactoryInterface;
use InvalidArgumentException;

/**
 * Weighted pool that removes each drawn item.
 *
 * @deprecated v2.0.0 Use BoxPool with count=1 instead:
 *   BoxPool::of($items, $weightExtractor, fn($item) => 1)
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

    /**
     * @param list<T>   $items
     * @param list<int> $weights
     */
    private function __construct(
        array $items,
        array $weights,
        private readonly SelectorFactoryInterface $selectorFactory,
        private readonly RandomizerInterface $randomizer,
    ) {
        $this->items    = $items;
        $this->weights  = $weights;
        $this->selector = $items !== [] ? $selectorFactory->create($weights) : null;
    }

    /**
     * @template TItem
     * @param iterable<TItem>                    $items
     * @param \Closure(TItem): int               $weightExtractor
     * @param ItemFilterInterface<TItem>          $filter
     * @param SelectorFactoryInterface            $selectorFactory
     * @param RandomizerInterface                 $randomizer
     * @return self<TItem>
     */
    public static function of(
        iterable $items,
        \Closure $weightExtractor,
        ItemFilterInterface $filter = new PositiveValueFilter(),
        SelectorFactoryInterface $selectorFactory = new PrefixSumSelectorFactory(),
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
            $selectorFactory,
            $randomizer,
        );
    }

    /**
     * Draws one item and removes it from the pool.
     * O(n) rebuild after each removal.
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

        $this->selector = $this->items !== [] ? $this->selectorFactory->create($this->weights) : null;

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

        for ($i = 0; $i < $count && !$this->isEmpty(); $i++) {
            $results[] = $this->draw();
        }

        return $results;
    }

    public function isEmpty(): bool
    {
        return $this->selector === null;
    }
}
