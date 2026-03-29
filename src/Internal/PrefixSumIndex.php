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

        $acc  = 0;
        $sums = [];

        foreach ($weights as $w) {
            if ($w <= 0) {
                throw new \InvalidArgumentException("Each weight must be a positive integer, got {$w}.");
            }
            $acc   += $w;
            $sums[] = $acc;
        }

        $this->prefixSums = $sums;
        $this->total      = $acc;
    }

    /**
     * Returns the index of the selected item.
     *
     * @param int $rand a value in [0, total)
     */
    public function pick(int $rand): int
    {
        $lo = 0;
        $hi = count($this->prefixSums) - 1;

        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if ($this->prefixSums[$mid] <= $rand) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    public function total(): int
    {
        return $this->total;
    }
}
