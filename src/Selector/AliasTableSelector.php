<?php

declare(strict_types=1);

namespace WeightedSample\Selector;

use WeightedSample\Randomizer\RandomizerInterface;

/**
 * Walker's Alias Method selector.
 *
 * Build: O(n) — constructs alias table from integer weights.
 * Pick:  O(1) — two random calls (column index + coin flip).
 *
 * Note: weights must be positive integers (same as PrefixSumSelector).
 * Internally, weights are normalized to float probabilities for the alias table.
 * The coin flip uses integer arithmetic: next(PHP_INT_MAX) / PHP_INT_MAX ≈ [0, 1).
 *
 * Use this selector when draw() is called frequently on large item sets.
 * For small sets or infrequent draws, PrefixSumSelector (integer-only) is sufficient.
 */
final class AliasTableSelector implements SelectorInterface
{
    /** @var list<float> */
    private array $probability;

    /** @var list<int> */
    private array $alias;

    private int $count;

    /**
     * @param list<int> $weights
     */
    private function __construct(array $weights)
    {
        $itemCount = count($weights);
        $this->count = $itemCount;
        $total = array_sum($weights);

        // Scale probabilities: scaled[i] = n * w[i] / W ∈ [0, n]
        $scaled = array_map(fn (int $w) => $itemCount * $w / $total, $weights);

        /** @var list<int> $small */
        $small = [];
        /** @var list<int> $large */
        $large = [];

        foreach ($scaled as $index => $scaledProbability) {
            if ($scaledProbability < 1.0) {
                $small[] = $index;
            } else {
                $large[] = $index;
            }
        }

        $probability = array_fill(0, $itemCount, 1.0);
        $alias       = array_fill(0, $itemCount, 0);

        while ($small !== [] && $large !== []) {
            $smallIndex = array_pop($small);
            $largeIndex = array_pop($large);

            $probability[$smallIndex] = $scaled[$smallIndex];
            $alias[$smallIndex]       = $largeIndex;

            $scaled[$largeIndex] = ($scaled[$largeIndex] + $scaled[$smallIndex]) - 1.0;

            if ($scaled[$largeIndex] < 1.0) {
                $small[] = $largeIndex;
            } else {
                $large[] = $largeIndex;
            }
        }

        /** @var list<float> $probability */
        $this->probability = $probability;
        /** @var list<int> $alias */
        $this->alias = $alias;
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
        $column   = $randomizer->next($this->count);
        $coinFlip = $randomizer->next(PHP_INT_MAX) / PHP_INT_MAX;

        return $coinFlip < $this->probability[$column] ? $column : $this->alias[$column];
    }
}
