<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\FenwickTreeSelector;
use WeightedSample\Selector\SelectorInterface;
use WeightedSample\Tests\Support\RandomizerHelpers;

class FenwickTreeSelectorTest extends TestCase
{
    use RandomizerHelpers;

    // -------------------------------------------------------------------------
    // インターフェース実装
    // -------------------------------------------------------------------------

    public function test_implements_selector_interface(): void
    {
        // Arrange & Act
        $selector = FenwickTreeSelector::build([10, 90]);

        // Assert
        $this->assertInstanceOf(SelectorInterface::class, $selector, 'FenwickTreeSelector が SelectorInterface を実装していること');
    }

    // -------------------------------------------------------------------------
    // バリデーション
    // -------------------------------------------------------------------------

    public function test_build_throws_on_empty_weights(): void
    {
        // Arrange & Act & Assert
        $this->expectException(InvalidArgumentException::class);
        FenwickTreeSelector::build([]);
    }

    /** @param list<int> $weights */
    #[DataProvider('nonPositiveWeightProvider')]
    public function test_build_throws_on_non_positive_weight(array $weights): void
    {
        // Arrange & Act & Assert
        $this->expectException(InvalidArgumentException::class);
        FenwickTreeSelector::build($weights);
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
        // Arrange — 2 つの重みの合計が PHP_INT_MAX を超える
        $half = intdiv(\PHP_INT_MAX, 2) + 1;

        // Act & Assert
        $this->expectException(OverflowException::class);
        FenwickTreeSelector::build([$half, $half]);
    }

    // -------------------------------------------------------------------------
    // pick() — 基本動作
    // -------------------------------------------------------------------------

    public function test_pick_returns_valid_index_range(): void
    {
        // Arrange
        $selector   = FenwickTreeSelector::build([10, 30, 60]);
        $randomizer = new SeededRandomizer(42);

        // Act & Assert — 100 回引いてすべて有効なインデックスであること
        for ($i = 0; $i < 100; $i++) {
            $result = $selector->pick($randomizer);
            $this->assertContains($result, [0, 1, 2], "{$i} 回目の pick が有効なインデックスを返すこと");
        }
    }

    public function test_single_item_always_returns_zero(): void
    {
        // Arrange
        $selector = FenwickTreeSelector::build([100]);

        // Act & Assert
        $this->assertSame(0, $selector->pick($this->fixedRandomizer(0)), 'アイテムが 1 つのとき pick は常に 0 を返すこと（r=0）');
        $this->assertSame(0, $selector->pick($this->fixedRandomizer(99)), 'アイテムが 1 つのとき pick は常に 0 を返すこと（r=99）');
    }

    public function test_pick_boundary_below_first_threshold(): void
    {
        // Arrange — weights=[10, 90], total=100
        //   prefix sums: [10, 100]
        //   r=9  → cumsum[0]=10 > 9  → index 0
        $selector = FenwickTreeSelector::build([10, 90]);

        // Act & Assert
        $this->assertSame(0, $selector->pick($this->fixedRandomizer(9)), 'r=9 のとき index 0 が返ること（第 1 アイテムの帯の末尾）');
    }

    public function test_pick_boundary_at_first_threshold(): void
    {
        // Arrange — weights=[10, 90], total=100
        //   r=10 → cumsum[0]=10 ≤ 10, cumsum[1]=100 > 10 → index 1
        $selector = FenwickTreeSelector::build([10, 90]);

        // Act & Assert
        $this->assertSame(1, $selector->pick($this->fixedRandomizer(10)), 'r=10 のとき index 1 が返ること（第 2 アイテムの帯の先頭）');
    }

    public function test_all_items_appear_in_sufficient_draws(): void
    {
        // Arrange — 重み [1, 9, 90] で 10000 回引いたとき全アイテムが出ること
        $selector   = FenwickTreeSelector::build([1, 9, 90]);
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
        $selector   = FenwickTreeSelector::build([1, 9, 90]);
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

    public function test_pick_distribution_after_update_matches_expected_proportions(): void
    {
        // Arrange — update(0, 0) で index 0 を除外後、残り [30, 70] の分布を確認
        $selector = FenwickTreeSelector::build([30, 30, 70]);
        $selector->update(0, 0); // index 0 除外 → 実質 [0, 30, 70], total=100

        $randomizer = new SeededRandomizer(42);
        $counts     = [0, 0, 0];
        $draws      = 100_000;

        // Act
        for ($i = 0; $i < $draws; $i++) {
            $counts[$selector->pick($randomizer)]++;
        }

        // Assert — index 0 は出ない、index 1 は 30%, index 2 は 70%
        // 3σ = 3 × sqrt(p(1−p) / N): p=0.30 → 0.43%, p=0.70 → 0.43% なので delta=0.5% は保守的に安全
        $this->assertSame(0, $counts[0], 'update(0, 0) 後に index 0 が一度も選ばれないこと');
        $this->assertEqualsWithDelta(30.0, $counts[1] / $draws * 100, 0.5, 'index 1 の出現率が 30% ±0.5% の範囲内であること');
        $this->assertEqualsWithDelta(70.0, $counts[2] / $draws * 100, 0.5, 'index 2 の出現率が 70% ±0.5% の範囲内であること');
    }

    // -------------------------------------------------------------------------
    // totalWeight()
    // -------------------------------------------------------------------------

    public function test_total_weight_equals_sum_of_initial_weights(): void
    {
        // Arrange & Act
        $selector = FenwickTreeSelector::build([10, 30, 60]);

        // Assert
        $this->assertSame(100, $selector->totalWeight(), '初期の totalWeight が重みの合計 100 であること');
    }

    // -------------------------------------------------------------------------
    // update()
    // -------------------------------------------------------------------------

    public function test_update_to_zero_excludes_item_from_picks(): void
    {
        // Arrange — weights=[10, 90], index 0 を除外
        $selector = FenwickTreeSelector::build([10, 90]);

        // Act
        $selector->update(0, 0);

        // Assert — index 0 は二度と選ばれない
        $seen = [];
        $rng  = new SeededRandomizer(42);
        for ($i = 0; $i < 1000; $i++) {
            $seen[$selector->pick($rng)] = true;
        }
        $this->assertArrayNotHasKey(0, $seen, 'update(0, 0) 後に index 0 が選ばれないこと');
        $this->assertArrayHasKey(1, $seen, 'update(0, 0) 後も index 1 は選ばれること');
    }

    public function test_update_decreases_total_weight(): void
    {
        // Arrange
        $selector = FenwickTreeSelector::build([10, 30, 60]);

        // Act — index 0 (weight=10) を除外
        $selector->update(0, 0);

        // Assert
        $this->assertSame(90, $selector->totalWeight(), 'update(0, 0) 後の totalWeight が 90 になること');
    }

    public function test_update_to_zero_is_idempotent(): void
    {
        // Arrange — index 0 を 2 回 0 にしても totalWeight と挙動が安定していること
        $selector = FenwickTreeSelector::build([10, 90]);
        $selector->update(0, 0); // 1 回目: 10 → 0

        // Act — 2 回目: 既に 0 なので差分なし
        $selector->update(0, 0);

        // Assert — totalWeight は変わらず、index 0 は選ばれない
        $this->assertSame(90, $selector->totalWeight(), '2 回 update(0, 0) しても totalWeight は変わらないこと');

        $seen = [];
        $rng  = new SeededRandomizer(42);
        for ($i = 0; $i < 1000; $i++) {
            $seen[$selector->pick($rng)] = true;
        }
        $this->assertArrayNotHasKey(0, $seen, '2 回 update(0, 0) 後も index 0 が選ばれないこと');
    }

    public function test_update_all_to_zero_makes_total_weight_zero(): void
    {
        // Arrange
        $selector = FenwickTreeSelector::build([10, 30, 60]);

        // Act
        $selector->update(0, 0);
        $selector->update(1, 0);
        $selector->update(2, 0);

        // Assert
        $this->assertSame(0, $selector->totalWeight(), '全アイテムを update(i, 0) すると totalWeight が 0 になること');
    }

    public function test_update_restores_weight(): void
    {
        // Arrange — index 1 を一時除外してから復元
        $selector = FenwickTreeSelector::build([10, 30, 60]);
        $selector->update(1, 0);

        // Act
        $selector->update(1, 30);

        // Assert
        $this->assertSame(100, $selector->totalWeight(), '復元後の totalWeight が元の 100 に戻ること');

        $seen = [];
        $rng  = new SeededRandomizer(42);
        for ($i = 0; $i < 5000; $i++) {
            $seen[$selector->pick($rng)] = true;
        }
        $this->assertArrayHasKey(1, $seen, '復元後に index 1 が再び選ばれること');
    }

    public function test_update_boundary_last_item_excluded(): void
    {
        // Arrange — 最後のアイテムを除外
        $selector = FenwickTreeSelector::build([10, 30, 60]);
        $selector->update(2, 0);

        // Assert — index 2 は選ばれない
        $seen = [];
        $rng  = new SeededRandomizer(42);
        for ($i = 0; $i < 1000; $i++) {
            $seen[$selector->pick($rng)] = true;
        }
        $this->assertArrayNotHasKey(2, $seen, 'update(2, 0) 後に index 2 が選ばれないこと');
    }

    public function test_update_throws_on_negative_weight(): void
    {
        // Arrange
        $selector = FenwickTreeSelector::build([10, 30, 60]);

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Weight must be non-negative');

        // Act
        $selector->update(0, -1);
    }

    public function test_update_throws_on_out_of_range_index(): void
    {
        // Arrange
        $selector = FenwickTreeSelector::build([10, 30, 60]);

        // Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        $selector->update(3, 10);
    }

    public function test_update_throws_on_overflow_when_new_weight_is_too_large(): void
    {
        // Arrange — 2アイテム合計 total=2 の状態から index=1 を PHP_INT_MAX に更新すると
        //   delta = PHP_INT_MAX - 1、newTotal = 2 + (PHP_INT_MAX - 1) = PHP_INT_MAX + 1 → オーバーフロー
        $selector = FenwickTreeSelector::build([1, 1]);

        // Assert
        $this->expectException(OverflowException::class);

        // Act
        $selector->update(1, \PHP_INT_MAX);
    }

    // -------------------------------------------------------------------------
    // onItemExcluded() — ItemExclusionObserverInterface 実装
    // -------------------------------------------------------------------------

    public function test_on_item_excluded_removes_item_from_picks(): void
    {
        // Arrange — weights=[10, 90]、Observer 経由で index=0 を除外
        $selector = FenwickTreeSelector::build([10, 90]);

        // Act
        $selector->onItemExcluded(0);

        // Assert — update(0, 0) と等価: totalWeight が 90 になり index 0 は選ばれない
        $this->assertSame(90, $selector->totalWeight(), 'onItemExcluded(0) 後の totalWeight が 90 になること');

        $seen = [];
        $rng  = new SeededRandomizer(42);
        for ($i = 0; $i < 1000; $i++) {
            $seen[$selector->pick($rng)] = true;
        }
        $this->assertArrayNotHasKey(0, $seen, 'onItemExcluded(0) 後に index 0 が選ばれないこと');
        $this->assertArrayHasKey(1, $seen, 'onItemExcluded(0) 後も index 1 は選ばれること');
    }

    public function test_on_item_excluded_is_idempotent(): void
    {
        // Arrange
        $selector = FenwickTreeSelector::build([10, 90]);
        $selector->onItemExcluded(0);

        // Act — 2 回目の呼び出しは安全に無視される
        $selector->onItemExcluded(0);

        // Assert — totalWeight は 90 のまま変わらない
        $this->assertSame(90, $selector->totalWeight(), '2 回 onItemExcluded(0) しても totalWeight は変わらないこと');
    }

}
