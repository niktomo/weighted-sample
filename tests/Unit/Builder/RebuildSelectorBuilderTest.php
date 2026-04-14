<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit\Builder;

use LogicException;
use PHPUnit\Framework\TestCase;
use WeightedSample\Builder\RebuildSelectorBuilder;
use WeightedSample\Builder\SelectorBuilderInterface;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\PrefixSumSelectorFactory;
use WeightedSample\Tests\Support\RandomizerHelpers;

class RebuildSelectorBuilderTest extends TestCase
{
    use RandomizerHelpers;

    // -------------------------------------------------------------------------
    // インターフェース実装
    // -------------------------------------------------------------------------

    public function test_implements_selector_builder_interface(): void
    {
        // Arrange & Act
        $builder = new RebuildSelectorBuilder(new PrefixSumSelectorFactory(), [3, 5, 2]);

        // Assert
        $this->assertInstanceOf(SelectorBuilderInterface::class, $builder, 'RebuildSelectorBuilder が SelectorBuilderInterface を実装していること');
    }

    // -------------------------------------------------------------------------
    // totalWeight — O(1) キャッシュ
    // -------------------------------------------------------------------------

    public function test_total_weight_returns_initial_sum(): void
    {
        // Arrange
        $builder = new RebuildSelectorBuilder(new PrefixSumSelectorFactory(), [3, 5, 2]);

        // Act & Assert
        $this->assertSame(10, $builder->totalWeight(), '初期 totalWeight が weights の合計と等しいこと');
    }

    public function test_total_weight_decreases_after_subtract(): void
    {
        // Arrange
        $builder = new RebuildSelectorBuilder(new PrefixSumSelectorFactory(), [3, 5, 2]);

        // Act
        $builder->subtract(0); // weight=3 を差し引く

        // Assert
        $this->assertSame(7, $builder->totalWeight(), 'subtract 後に totalWeight が weight 分だけ減ること');
    }

    public function test_total_weight_reaches_zero_when_all_subtracted(): void
    {
        // Arrange
        $builder = new RebuildSelectorBuilder(new PrefixSumSelectorFactory(), [3, 5, 2]);

        // Act
        $builder->subtract(0);
        $builder->subtract(1);
        $builder->subtract(2);

        // Assert
        $this->assertSame(0, $builder->totalWeight(), '全アイテムを subtract すると totalWeight が 0 になること');
    }

    // -------------------------------------------------------------------------
    // currentSelector — subtract 後は新インスタンス（rebuild）
    // -------------------------------------------------------------------------

    public function test_current_selector_changes_after_subtract(): void
    {
        // Arrange
        $builder = new RebuildSelectorBuilder(new PrefixSumSelectorFactory(), [3, 5, 2]);
        $before  = $builder->currentSelector();

        // Act
        $builder->subtract(0);

        // Assert — rebuild により新しいインスタンスが返ること（FenwickBuilder とは逆）
        $this->assertNotSame($before, $builder->currentSelector(), 'subtract 後に currentSelector() が新しいインスタンスを返すこと（rebuild）');
    }

    // -------------------------------------------------------------------------
    // subtract → pick に即時反映（インデックスマッピング）
    // -------------------------------------------------------------------------

    public function test_subtract_excludes_index_from_future_picks(): void
    {
        // Arrange — weights=[10, 10, 10]、index=1 を差し引く
        $builder = new RebuildSelectorBuilder(new PrefixSumSelectorFactory(), [10, 10, 10]);
        $builder->subtract(1);

        $randomizer = new SeededRandomizer(42);
        $seen       = [];

        // Act — 300 回 pick して index=1 が出ないことを確認
        for ($i = 0; $i < 300; $i++) {
            $seen[$builder->currentSelector()->pick($randomizer)] = true;
        }

        // Assert — 元インデックスで返ること
        $this->assertArrayNotHasKey(1, $seen, 'subtract した index=1 が選ばれないこと');
        $this->assertArrayHasKey(0, $seen, 'index=0 は引き続き元インデックスで選ばれること');
        $this->assertArrayHasKey(2, $seen, 'index=2 は引き続き元インデックスで選ばれること');
    }

    public function test_subtract_first_index_still_returns_original_indices(): void
    {
        // Arrange — weights=[10, 10]、index=0 を差し引く → 残りは index=1 のみ
        $builder = new RebuildSelectorBuilder(new PrefixSumSelectorFactory(), [10, 10]);
        $builder->subtract(0);

        $randomizer = $this->fixedRandomizer(0);

        // Act & Assert — コンパクト後の index 0 が元の index 1 にマップされること
        $this->assertSame(1, $builder->currentSelector()->pick($randomizer), 'index=0 を subtract した後、残った index=1 が正しく返ること');
    }

    // -------------------------------------------------------------------------
    // 不変条件違反
    // -------------------------------------------------------------------------

    public function test_current_selector_throws_logic_exception_when_all_weights_are_zero(): void
    {
        // Arrange — 全アイテムを subtract してから currentSelector() を呼ぶ
        // Pool は totalWeight() === 0 を確認してから currentSelector() を呼ぶべきであり、
        // この呼び出しはプログラミングエラーを示す
        $builder = new RebuildSelectorBuilder(new PrefixSumSelectorFactory(), [10, 20]);
        $builder->subtract(0);
        $builder->subtract(1);

        // Act & Assert
        $this->expectException(LogicException::class);
        $builder->currentSelector();
    }

    // -------------------------------------------------------------------------
    // subtract 冪等性
    // -------------------------------------------------------------------------

    public function test_subtract_same_index_twice_is_idempotent(): void
    {
        // Arrange
        $builder = new RebuildSelectorBuilder(new PrefixSumSelectorFactory(), [10, 20, 30]);
        $builder->subtract(1);
        $selectorAfterFirst = $builder->currentSelector(); // rebuild 済み

        // Act — 同じ index を再度 subtract しても状態が変わらない
        $builder->subtract(1);

        // Assert — dirty フラグが立たないので currentSelector は同じインスタンスのまま
        $this->assertSame(40, $builder->totalWeight(), '2 回 subtract しても totalWeight は変わらないこと');
        $this->assertSame($selectorAfterFirst, $builder->currentSelector(), '2 回 subtract しても不要な rebuild が発生しないこと');
    }

    // -------------------------------------------------------------------------
    // ヘルパー
    // -------------------------------------------------------------------------

}
