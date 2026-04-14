<?php

declare(strict_types=1);

namespace WeightedSample\Selector;

use InvalidArgumentException;
use OverflowException;
use WeightedSample\Randomizer\RandomizerInterface;

/**
 * Walker's Alias Method selector.
 *
 * Build: O(n) — constructs alias table from integer weights using pure integer arithmetic.
 * Pick:  O(1) — single random call, pure integer arithmetic.
 *
 *   r = next(n × W)
 *   column    = r / W   — bucket index [0, n)
 *   coinValue = r % W   — coin flip value [0, W)
 *   return coinValue < threshold[column] ? column : alias[column]
 *
 * Constraint: (n + 1) × W must not exceed PHP_INT_MAX (≈ 9.2 × 10^18).
 *   The +1 provides headroom for the intermediate sum prob[l] + prob[s] in Vose's algorithm.
 *   Example: 10,000 items with total weight 1,000,000 → 10,001 × 10^6 ≈ 10^10 (safe).
 *
 * Integer-only build:
 *   prob[i] = n × w[i] replaces the float-scaled probability n × w[i] / W.
 *   Partition: prob[i] < W → small, prob[i] ≥ W → large.
 *   Redistribution: prob[l] = prob[l] + prob[s] − W  (exact integer, no rounding error).
 *   threshold[s] = prob[s] directly — no float conversion or round() needed.
 *   This eliminates the ±1/W bias present in float-based implementations.
 *
 * Use this selector when draw() is called frequently on large item sets.
 * For small sets or infrequent draws, PrefixSumSelector (integer-only) is sufficient.
 *
 * @see https://www.keithschwarz.com/darts-dice-coins/ Vose's Alias Method explanation and proof of correctness
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

        if ($itemCount === 0) {
            throw new InvalidArgumentException('weights must not be empty.');
        }

        $total = 0;
        foreach ($weights as $w) {
            if ($total > PHP_INT_MAX - $w) {
                throw new OverflowException(
                    'Total weight would exceed PHP_INT_MAX; reduce item count or individual weights.',
                );
            }
            $total += $w;
        }
        $this->total = $total;

        // Vose's algorithm uses prob[l] + prob[s] as an intermediate value.
        // prob[l] ≤ n × max(w[i]) ≤ n × W, and prob[s] < W, so the sum is at most (n+1) × W.
        // Require (n+1) × W ≤ PHP_INT_MAX: throw when n ≥ ⌊PHP_INT_MAX / W⌋,
        // because then n × W ≥ PHP_INT_MAX − W + 1, meaning (n+1) × W ≥ PHP_INT_MAX + 1.
        if ($total > 0 && $itemCount >= intdiv(\PHP_INT_MAX, $total)) {
            throw new OverflowException(
                "Too many items or total weight is too large (n={$itemCount}, totalWeight={$total}). Reduce the number of items or lower individual weights.",
            );
        }

        // Pass 1: compute integer probabilities and partition into small / large in one loop.
        //   prob[i] = n × w[i]  — integer, no division required
        //   small   if prob[i] < W  (weight underrepresented in its column)
        //   large   if prob[i] ≥ W
        //   threshold[i] defaults to W  ≡ probability = 1.0 (column always wins, alias unused)
        //   alias[i]     defaults to i  (safe sentinel; unreachable when threshold = W)
        /** @var list<int> $prob */
        $prob      = [];
        /** @var list<int> $threshold */
        $threshold = [];
        /** @var list<int> $alias */
        $alias     = [];
        /** @var list<int> $small */
        $small     = [];
        /** @var list<int> $large */
        $large     = [];

        foreach ($weights as $index => $weight) {
            if ($weight <= 0) {
                throw new InvalidArgumentException("Each weight must be a positive integer, {$weight} given.");
            }
            $p         = $itemCount * $weight;   // n × w[i], exact integer
            $prob[]    = $p;
            $threshold[] = $total;               // default: coinValue always < W → column wins
            $alias[]   = $index;                 // default alias: self
            if ($p < $total) {
                $small[] = $index;
            } else {
                $large[] = $index;
            }
        }

        // Pass 2: Vose's algorithm with pure integer arithmetic.
        //   threshold[s] = prob[s]  — exact, no float conversion or round() needed.
        //   prob[l]      = prob[l] + prob[s] − W  — strictly decreasing, stays in [1, n×W].
        //   With exact integer arithmetic, prob[s] is always in [1, W-1] for any small item
        //   (all weights are positive integers, validated above). No clamp is needed.
        while ($small !== [] && $large !== []) {
            $smallIndex = array_pop($small);
            $largeIndex = array_pop($large);

            $threshold[$smallIndex] = $prob[$smallIndex];
            $alias[$smallIndex]     = $largeIndex;

            $prob[$largeIndex] = $prob[$largeIndex] + $prob[$smallIndex] - $total;

            if ($prob[$largeIndex] < $total) {
                $small[] = $largeIndex;
            } else {
                $large[] = $largeIndex;
            }
        }

        $this->threshold = array_values($threshold);
        $this->alias     = array_values($alias);
    }

    /**
     * @param list<int> $weights
     */
    public static function build(array $weights): static
    {
        return new self($weights);
    }

    public function pick(RandomizerInterface $randomizer): int
    {
        $random    = $randomizer->next($this->count * $this->total);
        $column    = intdiv($random, $this->total);
        $coinValue = $random % $this->total;

        return $coinValue < $this->threshold[$column] ? $column : $this->alias[$column];
    }
}
