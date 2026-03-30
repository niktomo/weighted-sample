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
 * Immutable weighted pool. draw() always selects from the full item set.
 *
 * @template T
 * @implements PoolInterface<T>
 */
final readonly class WeightedPool implements PoolInterface
{
    /**
     * @param list<T> $items
     */
    private function __construct(
        private array $items,
        private SelectorInterface $selector,
        private RandomizerInterface $randomizer,
    ) {
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
            throw new EmptyPoolException('Cannot create a WeightedPool: no items remain after filtering.');
        }

        return new self(
            $filtered,
            $selectorClass::build(array_map($weightFn, $filtered)),
            $randomizer ?? new SeededRandomizer(),
        );
    }

    /**
     * @return T
     */
    public function draw(): mixed
    {
        $selectedIndex = $this->selector->pick($this->randomizer);

        return $this->items[$selectedIndex];
    }
}
