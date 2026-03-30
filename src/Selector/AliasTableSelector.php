<?php

declare(strict_types=1);

namespace WeightedSample\Selector;

use WeightedSample\Randomizer\RandomizerInterface;

/**
 * Walker's Alias Method selector.
 *
 * Build: O(n) — constructs alias table from integer weights.
 * Pick:  O(1) — single random call, pure integer arithmetic.
 *
 *   r = next(n × W)
 *   column    = r / W   — bucket index [0, n)
 *   coinValue = r % W   — coin flip value [0, W)
 *   return coinValue < threshold[column] ? column : alias[column]
 *
 * Constraint: n × W must not exceed PHP_INT_MAX (≈ 9.2 × 10^18).
 *   Example: 10,000 items with total weight 1,000,000 → n×W = 10^10 (safe).
 *
 * Note on floating-point during build:
 *   Vose's algorithm internally uses float to redistribute scaled probabilities.
 *   The final threshold[i] = round(probability[i] × W) converts back to int.
 *   Due to 53-bit float precision, threshold[i] may be off by ±1 in pathological
 *   cases (e.g. weights spanning many orders of magnitude), introducing a bias of
 *   at most 1/W. For typical integer weights this is negligible.
 *
 * Use this selector when draw() is called frequently on large item sets.
 * For small sets or infrequent draws, PrefixSumSelector (integer-only) is sufficient.
 */
final readonly class AliasTableSelector implements SelectorInterface
{
    /** @var list<int> */
    private array $threshold;

    /** @var list<int> */
    private array $alias;

    private int $count;

    private int $total;

    /**
     * @param list<int> $weights
     */
    private function __construct(array $weights)
    {
        $itemCount = count($weights);
        $this->count = $itemCount;
        $total = array_sum($weights);
        $this->total = $total;

        if ($total > 0 && $itemCount > intdiv(\PHP_INT_MAX, $total)) {
            throw new \OverflowException(
                "n × W ({$itemCount} × {$total}) would exceed PHP_INT_MAX. Reduce item count or total weight.",
            );
        }

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

        // float probability を整数閾値に変換: threshold[i] = round(probability[i] * W)
        // coin_value (= r % W ∈ [0, W)) と比較するための分子
        /** @var list<int> $threshold */
        $threshold = array_map(fn (float $probability) => (int) round($probability * $total), $probability);
        $this->threshold = $threshold;
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
        $random    = $randomizer->next($this->count * $this->total);
        $column    = intdiv($random, $this->total);
        $coinValue = $random % $this->total;

        return $coinValue < $this->threshold[$column] ? $column : $this->alias[$column];
    }
}
