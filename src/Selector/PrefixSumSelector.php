<?php

declare(strict_types=1);

namespace WeightedSample\Selector;

use WeightedSample\Internal\PrefixSumIndex;
use WeightedSample\Randomizer\RandomizerInterface;

/**
 * Prefix sum + binary search selector.
 *
 * Build: O(n) — constructs prefix sum array from integer weights.
 * Pick:  O(log n) — single random call, binary search on prefix sums.
 *
 * Uses integer arithmetic only — no floating-point operations.
 * This is the default selector and is suitable for most use cases.
 */
final class PrefixSumSelector implements SelectorInterface
{
    private PrefixSumIndex $index;

    /**
     * @param list<int> $weights
     */
    private function __construct(array $weights)
    {
        $this->index = new PrefixSumIndex($weights);
    }

    /**
     * @param list<int> $weights
     */
    public static function build(array $weights): static
    {
        return new static($weights);
    }

    public function pick(RandomizerInterface $randomizer): int
    {
        return $this->index->pick($randomizer->next($this->index->total()));
    }
}
