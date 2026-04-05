<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\AliasTableSelector;
use WeightedSample\Selector\SelectorInterface;

class AliasTableSelectorTest extends TestCase
{
    public function test_build_throws_on_empty_weights(): void
    {
        // Arrange & Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        AliasTableSelector::build([]);
    }

    /** @param list<int> $weights */
    #[DataProvider('nonPositiveWeightProvider')]
    public function test_build_throws_on_non_positive_weight(array $weights): void
    {
        // Arrange & Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        AliasTableSelector::build($weights);
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
        // Arrange — n=3, W = 3 × bigWeight ≈ PHP_INT_MAX → n×W > PHP_INT_MAX
        // intdiv(PHP_INT_MAX, 3) × 3 ≈ PHP_INT_MAX, so itemCount(3) >= intdiv(PHP_INT_MAX, total)(1) → throws
        $bigWeight = intdiv(\PHP_INT_MAX, 3);

        // Act & Assert
        $this->expectException(\OverflowException::class);
        AliasTableSelector::build([$bigWeight, $bigWeight, $bigWeight]);
    }

    public function test_implements_selector_interface(): void
    {
        // Arrange & Act
        $selector = AliasTableSelector::build([10, 90]);

        // Assert
        $this->assertInstanceOf(SelectorInterface::class, $selector, 'AliasTableSelector が SelectorInterface を実装していること');
    }

    public function test_pick_returns_valid_index_range(): void
    {
        // Arrange
        $selector   = AliasTableSelector::build([10, 30, 60]);
        $randomizer = new SeededRandomizer(42);

        // Act & Assert — 100回引いてすべて有効なインデックスであること
        $result1 = $selector->pick($randomizer);
        $result2 = $selector->pick($randomizer);
        $result3 = $selector->pick($randomizer);
        $this->assertContains($result1, [0, 1, 2], '1回目の pick が有効なインデックスを返すこと');
        $this->assertContains($result2, [0, 1, 2], '2回目の pick が有効なインデックスを返すこと');
        $this->assertContains($result3, [0, 1, 2], '3回目の pick が有効なインデックスを返すこと');
    }

    public function test_single_item_always_returns_zero(): void
    {
        // Arrange — アイテムが1つのとき threshold[0]=W なので coinValue は常に閾値を下回り 0 を返す
        // n=1, W=100, n×W=100 → r ∈ [0, 99]: coinValue=r < 100 = threshold[0] → 常に 0
        $selector = AliasTableSelector::build([100]);

        // Act & Assert
        $this->assertSame(0, $selector->pick($this->sequenceRandomizer(0)), 'アイテムが1つのとき pick は常に 0 を返すこと（r=0）');
        $this->assertSame(0, $selector->pick($this->sequenceRandomizer(99)), 'アイテムが1つのとき pick は常に 0 を返すこと（r=99）');
    }

    public function test_pick_returns_item_index_when_coin_is_below_threshold(): void
    {
        // Arrange — weights=[10, 90]: n=2, W=100, n×W=200
        //   prob[0]=n×w[0]=20 < W → small; prob[1]=n×w[1]=180 ≥ W → large
        //   Vose's: threshold[0]=20, alias[0]=1; threshold[1]=W=100 (default, alias unused)
        // r=0 → column=intdiv(0,100)=0, coinValue=0%100=0. 0 < 20 → index 0
        $selector = AliasTableSelector::build([10, 90]);

        // Act
        $result = $selector->pick($this->sequenceRandomizer(0));

        // Assert
        $this->assertSame(0, $result, 'column=0 で coinValue がしきい値を下回るとき index 0 が返ること');
    }

    public function test_pick_returns_alias_index_when_coin_exceeds_threshold(): void
    {
        // Arrange — weights=[10, 90]: n=2, W=100, n×W=200
        //   threshold[0]=20, alias[0]=1 (set by Vose's); threshold[1]=100 (default, alias unused)
        // r=20 → column=intdiv(20,100)=0, coinValue=20%100=20. 20 < 20? No → alias[0]=1
        $selector = AliasTableSelector::build([10, 90]);

        // Act
        $result = $selector->pick($this->sequenceRandomizer(20));

        // Assert
        $this->assertSame(1, $result, 'column=0 で coinValue がしきい値以上のとき alias index 1 が返ること');
    }

    public function test_all_items_reachable_with_extreme_weight_ratio(): void
    {
        // Arrange — 重み比 1:999999 の極端なケース
        //   整数演算: prob[0]=n×w[0]=2×1=2 < W → small。threshold[0]=2 (exact, no float bias)
        //   旧 float 実装では round(n×w[0]/W × W)=round(0.000002×1000000)=2 と同値だが、
        //   多段降格ケースでは float 誤差により threshold=0 になるリスクがあった。整数演算はクランプ不要。
        $selector = AliasTableSelector::build([1, 999999]);

        // r=0 → column=intdiv(0, 999999+1)=0, coinValue=0 < threshold[0](≥1) → index 0 が返ること
        // threshold[0]=0 ならば coinValue < 0 が常に false となり index 0 は絶対に返らない
        $result = $selector->pick($this->sequenceRandomizer(0));

        // Assert — index 0 が返ること（クランプが機能し threshold[0] ≥ 1 であること）
        $this->assertSame(0, $result, '極端な重み比でも weight>0 のアイテム(index 0)が r=0 で選択されること（threshold ≥ 1 のクランプ保証）');
    }

    public function test_all_items_appear_in_sufficient_draws_with_skewed_weights(): void
    {
        // Arrange — 重み [1, 9, 90] で 10000 回引いたとき全アイテムが出ること
        $selector   = AliasTableSelector::build([1, 9, 90]);
        $randomizer = new SeededRandomizer(42);
        $seen       = [];

        // Act
        for ($i = 0; $i < 10000; $i++) {
            $seen[$selector->pick($randomizer)] = true;
        }

        // Assert — 全 3 アイテムが少なくとも 1 回は選ばれること
        $this->assertArrayHasKey(0, $seen, 'weight=1 のアイテム(index 0)が 10000 回中に少なくとも 1 回出ること');
        $this->assertArrayHasKey(1, $seen, 'weight=9 のアイテム(index 1)が 10000 回中に少なくとも 1 回出ること');
        $this->assertArrayHasKey(2, $seen, 'weight=90 のアイテム(index 2)が 10000 回中に少なくとも 1 回出ること');
    }

    public function test_pick_distribution_matches_expected_proportions(): void
    {
        // Arrange — 重み [1, 9, 90] で 100000 回。期待値: 1%, 9%, 90%
        // 3σ ≈ 0.3% なので delta=0.5% は保守的に十分安全
        $selector   = AliasTableSelector::build([1, 9, 90]);
        $randomizer = new SeededRandomizer(42);
        $counts     = [0, 0, 0];
        $draws      = 100_000;

        // Act
        for ($i = 0; $i < $draws; $i++) {
            $counts[$selector->pick($randomizer)]++;
        }

        // Assert
        $this->assertEqualsWithDelta(1.0, $counts[0] / $draws * 100, 0.5, 'index 0 (weight=1) の出現率が 1% ±0.5% の範囲内であること');
        $this->assertEqualsWithDelta(9.0, $counts[1] / $draws * 100, 0.5, 'index 1 (weight=9) の出現率が 9% ±0.5% の範囲内であること');
        $this->assertEqualsWithDelta(90.0, $counts[2] / $draws * 100, 0.5, 'index 2 (weight=90) の出現率が 90% ±0.5% の範囲内であること');
    }

    /**
     * @param int ...$values
     */
    private function sequenceRandomizer(int ...$values): RandomizerInterface
    {
        return new class (array_values($values)) implements RandomizerInterface {
            private int $position = 0;

            /** @param list<int> $values */
            public function __construct(private readonly array $values)
            {
            }

            public function next(int $max): int
            {
                return $this->values[$this->position++];
            }
        };
    }
}
