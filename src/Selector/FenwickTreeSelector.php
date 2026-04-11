<?php

declare(strict_types=1);

namespace WeightedSample\Selector;

use InvalidArgumentException;
use OverflowException;
use WeightedSample\Randomizer\RandomizerInterface;

/**
 * Fenwick tree (Binary Indexed Tree) weighted selector with O(log n) updates.
 *
 * Build:  O(n) — constructs Fenwick tree from integer weights.
 * Pick:   O(log n) — tree descent, single random call, integer arithmetic.
 * Update: O(log n) — point update propagates through tree.
 *
 * Prefer this selector over PrefixSumSelector when the pool mutates on every
 * draw() (DestructivePool, BoxPool): update() avoids the O(n) full rebuild
 * that PrefixSumSelector requires after each removal.
 *
 * For immutable pools (WeightedPool) pick performance equals PrefixSumSelector.
 * Use AliasTableSelector for O(1) picks on immutable pools.
 *
 * Constraint: total weight W must not exceed PHP_INT_MAX.
 *
 * Algorithm (pick):
 *   r = next(W)
 *   Descent finds the largest 1-indexed position j where prefixSum(j) ≤ r.
 *   Return j as the 0-indexed answer (the item whose cumulative range contains r).
 */
final class FenwickTreeSelector implements SelectorInterface
{
    /** @var array<int, int>  1-indexed Fenwick tree; tree[0] is unused (always 0) */
    private array $tree;

    /** @var array<int, int>  current weights, 0-indexed */
    private array $weights;

    private int $size;

    private int $total;

    /** Highest power of 2 ≤ $size; cached for O(log n) descent */
    private int $highBit;

    /**
     * @param list<int> $weights
     */
    private function __construct(array $weights)
    {
        $size = count($weights);

        if ($size === 0) {
            throw new InvalidArgumentException('weights must not be empty.');
        }

        $this->size  = $size;
        $this->total = 0;

        foreach ($weights as $weight) {
            if ($weight <= 0) {
                throw new InvalidArgumentException("Each weight must be a positive integer, {$weight} given.");
            }
            if ($this->total > \PHP_INT_MAX - $weight) {
                throw new OverflowException('Total weight exceeds PHP_INT_MAX.');
            }
            $this->total += $weight;
        }

        $this->weights = $weights;

        // Build Fenwick tree in O(n):
        //   each element pushes its value up to its parent exactly once.
        $tree = array_fill(0, $size + 1, 0);
        foreach ($weights as $i => $weight) {
            $pos         = $i + 1;               // convert to 1-indexed
            $tree[$pos] += $weight;
            $parent      = $pos + ($pos & -$pos); // parent in Fenwick tree
            if ($parent <= $size) {
                $tree[$parent] += $tree[$pos];
            }
        }
        $this->tree = $tree;

        // Pre-compute highest power of 2 ≤ size for pick() descent
        $highBit = 1;
        while (($highBit << 1) <= $size) {
            $highBit <<= 1;
        }
        $this->highBit = $highBit;
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
        $r       = $randomizer->next($this->total);
        $pos     = 0;
        $bitMask = $this->highBit;

        // O(log n) binary descent on the Fenwick tree.
        //
        // Goal: find the 0-indexed item i such that
        //   prefixSum(i) ≤ r < prefixSum(i + 1),
        //   i.e. the item whose cumulative weight range contains r.
        //
        // The Fenwick tree forms an implicit complete binary tree of height
        // ⌈log₂(n)⌉.  $bitMask starts at the highest power of 2 ≤ $size and
        // halves on every iteration — exactly ⌈log₂(size)⌉ probes, O(log n).
        //
        // At each step, $this->tree[$pos + $bitMask] stores the total weight of
        // the subtree rooted at that node.
        //   - If that subtree sum ≤ remaining $r: the target lies further right;
        //     advance $pos past the subtree and subtract its sum from $r.
        //   - Otherwise: the target lies to the left; $pos stays unchanged.
        //
        // At termination, $pos ∈ [0, size) is the 0-indexed item index.
        // Starting $pos at 0 and advancing by Fenwick $bitMask values yields the
        // correct 0-indexed result directly — no off-by-one adjustment needed.
        while ($bitMask > 0) {
            $next = $pos + $bitMask;
            if ($next <= $this->size && $this->tree[$next] <= $r) {
                $pos  = $next;
                $r   -= $this->tree[$pos];
            }
            $bitMask >>= 1;
        }

        return $pos;
    }

    public function update(int $index, int $newWeight): void
    {
        $delta               = $newWeight - $this->weights[$index];
        $this->weights[$index] = $newWeight;
        $this->total        += $delta;

        // Propagate delta up the Fenwick tree (1-indexed)
        $i = $index + 1;
        while ($i <= $this->size) {
            $this->tree[$i] += $delta;
            $i              += $i & (-$i); // move to next node covering this position
        }
    }

    public function totalWeight(): int
    {
        return $this->total;
    }
}
