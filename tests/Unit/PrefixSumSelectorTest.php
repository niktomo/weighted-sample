<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\PrefixSumSelector;
use WeightedSample\Selector\SelectorInterface;
use WeightedSample\Tests\Support\RandomizerHelpers;

class PrefixSumSelectorTest extends TestCase
{
    use RandomizerHelpers;

    // -------------------------------------------------------------------------
    // インターフェース実装
    // -------------------------------------------------------------------------

    public function test_implements_selector_interface(): void
    {
        // Arrange & Act
        $selector = PrefixSumSelector::build([10, 90]);

        // Assert
        $this->assertInstanceOf(SelectorInterface::class, $selector, 'PrefixSumSelector が SelectorInterface を実装していること');
    }

    // -------------------------------------------------------------------------
    // バリデーション
    // -------------------------------------------------------------------------

    public function test_build_throws_on_empty_weights(): void
    {
        // Arrange & Act & Assert
        $this->expectException(InvalidArgumentException::class);
        PrefixSumSelector::build([]);
    }

    /** @param list<int> $weights */
    #[DataProvider('nonPositiveWeightProvider')]
    public function test_build_throws_on_non_positive_weight(array $weights): void
    {
        // Arrange & Act & Assert
        $this->expectException(InvalidArgumentException::class);
        PrefixSumSelector::build($weights);
    }

    /** @return list<array{0: list<int>}> */
    public static function nonPositiveWeightProvider(): array
    {
        return [
            [[0, 10]],
            [[-1, 10]],
            [[0, 0]],
            [[10, -5, 90]],
        ];
    }

    public function test_build_throws_on_overflow(): void
    {
        // Arrange — 2つの重みの合計が PHP_INT_MAX を超える
        $half = intdiv(\PHP_INT_MAX, 2) + 1;

        // Act & Assert
        $this->expectException(OverflowException::class);
        PrefixSumSelector::build([$half, $half]);
    }

    public function test_pick_returns_first_index_when_rand_is_zero(): void
    {
        // Arrange
        $selector = PrefixSumSelector::build([10, 90]);

        // Act
        $result = $selector->pick($this->fixedRandomizer(0));

        // Assert
        $this->assertSame(0, $result, 'rand=0 のとき最初のインデックス 0 が返ること');
    }

    public function test_pick_returns_last_index_when_rand_is_total_minus_one(): void
    {
        // Arrange — total=100, rand=99
        $selector = PrefixSumSelector::build([10, 90]);

        // Act
        $result = $selector->pick($this->fixedRandomizer(99));

        // Assert
        $this->assertSame(1, $result, 'rand=total-1 のとき最後のインデックス 1 が返ること');
    }

    public function test_pick_returns_valid_index_for_single_item(): void
    {
        // Arrange
        $selector = PrefixSumSelector::build([42]);

        // Act
        $result = $selector->pick($this->fixedRandomizer(0));

        // Assert
        $this->assertSame(0, $result, 'アイテムが1つのとき常にインデックス 0 が返ること');
    }

    public function test_pick_boundary_at_first_threshold(): void
    {
        // Arrange — weights=[10, 90], total=100
        //   prefix sums: [10, 100]
        //   r=9  → prefixSums[0]=10 > 9  → index 0
        //   r=10 → prefixSums[0]=10 ≤ 10 → index 1
        $selector = PrefixSumSelector::build([10, 90]);

        // Act & Assert
        $this->assertSame(0, $selector->pick($this->fixedRandomizer(9)), 'r=9 のとき index 0 が返ること（第1アイテムの帯の末尾）');
        $this->assertSame(1, $selector->pick($this->fixedRandomizer(10)), 'r=10 のとき index 1 が返ること（第2アイテムの帯の先頭）');
    }

    public function test_all_items_appear_in_sufficient_draws(): void
    {
        // Arrange — 重み [1, 9, 90] で 10000 回引いたとき全アイテムが出ること
        $selector   = PrefixSumSelector::build([1, 9, 90]);
        $randomizer = new SeededRandomizer(42);
        $seen       = [];

        // Act
        for ($i = 0; $i < 10000; $i++) {
            $seen[$selector->pick($randomizer)] = true;
        }

        // Assert
        $this->assertArrayHasKey(0, $seen, 'weight=1 のアイテム(index 0)が 10000 回中に少なくとも 1 回出ること');
        $this->assertArrayHasKey(1, $seen, 'weight=9 のアイテム(index 1)が 10000 回中に少なくとも 1 回出ること');
        $this->assertArrayHasKey(2, $seen, 'weight=90 のアイテム(index 2)が 10000 回中に少なくとも 1 回出ること');
    }

    public function test_pick_distribution_matches_expected_proportions(): void
    {
        // Arrange — 重み [1, 9, 90] で 100000 回。期待値: 1%, 9%, 90%
        $selector   = PrefixSumSelector::build([1, 9, 90]);
        $randomizer = new SeededRandomizer(42);
        $counts     = [0, 0, 0];
        $draws      = 100_000;

        // Act
        for ($i = 0; $i < $draws; $i++) {
            $counts[$selector->pick($randomizer)]++;
        }

        // Assert — 3σ ≈ 0.3% なので delta=0.5% は保守的に十分安全
        $this->assertEqualsWithDelta(1.0, $counts[0] / $draws * 100, 0.5, 'index 0 (weight=1) の出現率が 1% ±0.5% の範囲内であること');
        $this->assertEqualsWithDelta(9.0, $counts[1] / $draws * 100, 0.5, 'index 1 (weight=9) の出現率が 9% ±0.5% の範囲内であること');
        $this->assertEqualsWithDelta(90.0, $counts[2] / $draws * 100, 0.5, 'index 2 (weight=90) の出現率が 90% ±0.5% の範囲内であること');
    }

}
