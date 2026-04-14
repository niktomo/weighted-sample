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
        $this->count = count($weights);
        if ($this->count === 0) {
            throw new InvalidArgumentException('weights must not be empty.');
        }
        $this->total = $this->computeTotal($weights);
        [$this->threshold, $this->alias] = $this->buildTable($weights);
    }

    /**
     * Validates weights, accumulates the total, and checks the (n+1)×W overflow constraint.
     *
     * @param list<int> $weights
     */
    private function computeTotal(array $weights): int
    {
        $total = 0;
        foreach ($weights as $w) {
            if ($total > PHP_INT_MAX - $w) {
                throw new OverflowException(
                    'Total weight would exceed PHP_INT_MAX; reduce item count or individual weights.',
                );
            }
            $total += $w;
        }

        // Vose's algorithm uses prob[l] + prob[s] as an intermediate value.
        // prob[l] ≤ n × max(w[i]) ≤ n × W, and prob[s] < W, so the sum is at most (n+1) × W.
        // Require (n+1) × W ≤ PHP_INT_MAX: throw when n ≥ ⌊PHP_INT_MAX / W⌋,
        // because then n × W ≥ PHP_INT_MAX − W + 1, meaning (n+1) × W ≥ PHP_INT_MAX + 1.
        if ($total > 0 && $this->count >= intdiv(\PHP_INT_MAX, $total)) {
            throw new OverflowException(
                "Too many items or total weight is too large (n={$this->count}, totalWeight={$total}). Reduce the number of items or lower individual weights.",
            );
        }

        return $total;
    }

    /**
     * Builds the threshold and alias arrays using Vose's integer-only alias method.
     *
     * Pass 1: compute prob[i] = n × w[i] and partition into small / large buckets.
     *   threshold[i] defaults to W (column always wins, alias unused).
     *   alias[i]     defaults to i (safe sentinel; unreachable when threshold = W).
     *
     * Pass 2: redistribute probability mass with pure integer arithmetic.
     *   threshold[s] = prob[s]              — exact, no float conversion needed.
     *   prob[l]      = prob[l] + prob[s] − W — strictly decreasing, stays in [1, n×W].
     *
     * @param list<int> $weights
     * @return array{list<int>, list<int>}
     */
    private function buildTable(array $weights): array
    {
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
            $p           = $this->count * $weight;
            $prob[]      = $p;
            $threshold[] = $this->total;
            $alias[]     = $index;
            if ($p < $this->total) {
                $small[] = $index;
            } else {
                $large[] = $index;
            }
        }

        // Termination guarantee (integer arithmetic):
        //   Total probability mass = n × W. Each iteration allocates exactly W to one column
        //   (threshold[$s] = prob[$s], and prob[$l] decreases by W - prob[$s]).
        //   After exactly n iterations, all n × W of mass is allocated → both lists are empty.
        //   With exact integer arithmetic (no float rounding), this holds perfectly;
        //   no residual handling is needed after the loop.
        while ($small !== [] && $large !== []) {
            $smallIndex = array_pop($small);
            $largeIndex = array_pop($large);

            $threshold[$smallIndex] = $prob[$smallIndex];
            $alias[$smallIndex]     = $largeIndex;

            $prob[$largeIndex] = $prob[$largeIndex] + $prob[$smallIndex] - $this->total;

            if ($prob[$largeIndex] < $this->total) {
                $small[] = $largeIndex;
            } else {
                $large[] = $largeIndex;
            }
        }

        return [array_values($threshold), array_values($alias)];
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
