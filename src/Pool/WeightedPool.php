<?php

declare(strict_types=1);

namespace WeightedSample\Pool;

use WeightedSample\Exception\AllItemsFilteredException;
use WeightedSample\Filter\ItemFilterInterface;
use WeightedSample\Filter\PositiveValueFilter;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Randomizer\SecureRandomizer;
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
     * @param iterable<TItem>                    $items
     * @param \Closure(TItem): int               $weightFn
     * @param ItemFilterInterface<TItem>          $filter
     * @param class-string<SelectorInterface>     $selectorClass
     * @param RandomizerInterface                 $randomizer
     * @return self<TItem>
     */
    public static function of(
        iterable $items,
        \Closure $weightFn,
        ItemFilterInterface $filter = new PositiveValueFilter(),
        string $selectorClass = PrefixSumSelector::class,
        RandomizerInterface $randomizer = new SecureRandomizer(),
    ): self {
        /** @var list<TItem> $filteredItems */
        $filteredItems = [];
        /** @var list<int> $filteredWeights */
        $filteredWeights = [];
        foreach ($items as $item) {
            $weight = $weightFn($item);
            if ($filter->accepts($item, $weight)) {
                $filteredItems[]   = $item;
                $filteredWeights[] = $weight;
            }
        }

        if ($filteredItems === []) {
            throw new AllItemsFilteredException('Cannot create a WeightedPool: no items remain after filtering.');
        }

        return new self(
            $filteredItems,
            $selectorClass::build($filteredWeights),
            $randomizer,
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
