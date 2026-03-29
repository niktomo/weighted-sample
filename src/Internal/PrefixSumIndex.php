<?php

declare(strict_types=1);

namespace WeightedSample\Internal;

/**
 * Prefix sum array + binary search for O(log n) weighted index selection.
 *
 * @internal
 */
final class PrefixSumIndex
{
    /** @var list<int> */
    private array $prefixSums;

    private int $total;

    /** @param list<int> $weights */
    public function __construct(array $weights)
    {
        if ($weights === []) {
            throw new \InvalidArgumentException('weights must not be empty.');
        }

        $runningTotal = 0;
        $sums         = [];

        foreach ($weights as $weight) {
            if ($weight <= 0) {
                throw new \InvalidArgumentException("Each weight must be a positive integer, {$weight} given.");
            }
            $runningTotal += $weight;
            $sums[]        = $runningTotal;
        }

        $this->prefixSums = $sums;
        $this->total      = $runningTotal;
    }

    /**
     * Returns the index of the selected item.
     *
     * @param int $randomValue a value in [0, total)
     */
    public function pick(int $randomValue): int
    {
        $low  = 0;
        $high = count($this->prefixSums) - 1;

        while ($low < $high) {
            $middle = ($low + $high) >> 1;
            if ($this->prefixSums[$middle] <= $randomValue) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    public function total(): int
    {
        return $this->total;
    }
}
