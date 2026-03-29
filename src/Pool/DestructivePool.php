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
 * Weighted pool that removes each drawn item.
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

    private ?PrefixSumIndex $index;

    /**
     * @param list<T>   $items
     * @param list<int> $weights  pre-computed weights parallel to $items
     */
    private function __construct(
        array $items,
        array $weights,
        private readonly RandomizerInterface $randomizer,
    ) {
        $this->items   = $items;
        $this->weights = $weights;
        $this->index   = $items !== [] ? new PrefixSumIndex($weights) : null;
    }

    /**
     * @template TItem
     * @param list<TItem>          $items
     * @param \Closure(TItem): int $weightFn
     * @return self<TItem>
     */
    public static function of(
        array $items,
        \Closure $weightFn,
        ?ItemFilterInterface $filter = null,
        ?RandomizerInterface $randomizer = null,
    ): self {
        $filter ??= new PositiveValueFilter();

        /** @var list<TItem> $filtered */
        $filtered = array_values(array_filter(
            $items,
            fn ($item) => $filter->accepts($item, $weightFn($item), null),
        ));

        if ($filtered === []) {
            throw new EmptyPoolException('Cannot create a DestructivePool: no items remain after filtering.');
        }

        return new self($filtered, array_map($weightFn, $filtered), $randomizer ?? new SeededRandomizer());
    }

    /**
     * Draws one item and removes it from the pool.
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

        array_splice($this->items, $selectedIndex, 1);
        array_splice($this->weights, $selectedIndex, 1);

        $this->index = $this->items !== [] ? new PrefixSumIndex($this->weights) : null;

        return $item;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
