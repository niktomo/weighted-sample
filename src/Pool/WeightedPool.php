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
 * Immutable weighted pool. draw() always selects from the full item set.
 *
 * @template T
 * @implements PoolInterface<T>
 */
final class WeightedPool implements PoolInterface
{
    private PrefixSumIndex $index;

    /**
     * @param list<T>          $items
     * @param \Closure(T): int $weightFn
     */
    private function __construct(
        private readonly array $items,
        \Closure $weightFn,
        private readonly RandomizerInterface $randomizer,
    ) {
        $this->index = new PrefixSumIndex(array_map($weightFn, $items));
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
            throw new EmptyPoolException('Cannot create a WeightedPool: no items remain after filtering.');
        }

        return new self($filtered, $weightFn, $randomizer ?? new SeededRandomizer());
    }

    /**
     * @return T
     */
    public function draw(): mixed
    {
        $randomValue   = $this->randomizer->next($this->index->total());
        $selectedIndex = $this->index->pick($randomValue);

        return $this->items[$selectedIndex];
    }

}
