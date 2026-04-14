<?php

declare(strict_types=1);

namespace WeightedSample\Selector;

use InvalidArgumentException;
use OverflowException;
use UnderflowException;
use WeightedSample\ItemExclusionObserverInterface;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\TotalWeightQueryInterface;

/**
 * Fenwick tree (Binary Indexed Tree) weighted selector with O(log n) updates.
 *
 * Build:  O(n) — constructs Fenwick tree from integer weights.
 * Pick:   O(log n) — tree descent, single random call, integer arithmetic.
 * Update: O(log n) — point update propagates through tree.
 *
 * Prefer this selector over PrefixSumSelector when the pool mutates on every
 * draw() (BoxPool): update() avoids the O(n) full rebuild
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
final class FenwickTreeSelector implements SelectorInterface, ItemExclusionObserverInterface, TotalWeightQueryInterface
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

        // Build Fenwick tree in O(n) using the propagation method:
        //   For each position $pos (1-indexed), add $weight to tree[$pos],
        //   then propagate that value to its Fenwick parent ($pos + lowbit($pos)).
        //   Each element is visited at most O(log n) times as a parent, giving O(n) total.
        //   Unlike the naive O(n log n) prefix-sum approach, no element is summed twice.
        $tree = array_fill(0, $size + 1, 0);
        foreach ($weights as $i => $weight) {
            $pos         = $i + 1;                // convert to 1-indexed
            $tree[$pos] += $weight;
            $parent      = $pos + ($pos & -$pos); // Fenwick parent: next node that covers $pos
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
        return new self($weights);
    }

    public function pick(RandomizerInterface $randomizer): int
    {
        if ($this->total === 0) {
            throw new UnderflowException('Cannot pick from a selector with zero total weight.');
        }

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

    /**
     * Updates the weight of the item at $index.
     *
     * Pass newWeight=0 to exclude an item from future picks (used by onItemExcluded()).
     * Calling update(i, 0) on an already-zero weight is a no-op (delta=0).
     *
     * @internal Intended to be called only via onItemExcluded(). Direct calls from
     *   outside this class may break BoxPool's invariant that weights only decrease to 0.
     *
     * @throws InvalidArgumentException if $index is out of range or $newWeight is negative
     * @throws OverflowException        if the resulting total weight would exceed PHP_INT_MAX
     */
    public function update(int $index, int $newWeight): void
    {
        if ($index < 0 || $index >= $this->size) {
            throw new InvalidArgumentException(
                "Index {$index} is out of range [0, {$this->size}).",
            );
        }
        if ($newWeight < 0) {
            throw new InvalidArgumentException(
                "Weight must be non-negative; {$newWeight} given.",
            );
        }

        $delta = $newWeight - $this->weights[$index];

        if ($delta > 0 && $this->total > \PHP_INT_MAX - $delta) {
            throw new OverflowException(
                "Total weight would exceed PHP_INT_MAX after update; index={$index}, newWeight={$newWeight}.",
            );
        }

        $newTotal = $this->total + $delta;

        if ($newTotal < 0) {
            throw new OverflowException(
                "Total weight cannot be negative after update; index={$index}, newWeight={$newWeight}.",
            );
        }

        $this->weights[$index] = $newWeight;
        $this->total           = $newTotal;

        // $this->highBit is not updated here: it depends only on $this->size,
        // which never changes after construction (update() mutates weights, not size).

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

    /**
     * Observer callback: excludes the item at $index by setting its weight to zero.
     * Called by FenwickSelectorBuilder when an item's stock reaches zero.
     */
    public function onItemExcluded(int $index): void
    {
        $this->update($index, 0);
    }
}
