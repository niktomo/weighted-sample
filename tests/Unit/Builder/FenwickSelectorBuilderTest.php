<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use WeightedSample\Builder\FenwickSelectorBuilder;
use WeightedSample\Builder\SelectorBuilderInterface;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\FenwickTreeSelector;

class FenwickSelectorBuilderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // インターフェース実装
    // -------------------------------------------------------------------------

    public function test_implements_selector_builder_interface(): void
    {
        // Arrange & Act
        $builder = new FenwickSelectorBuilder(FenwickTreeSelector::build([1, 2, 3]));

        // Assert
        $this->assertInstanceOf(SelectorBuilderInterface::class, $builder, 'FenwickSelectorBuilder が SelectorBuilderInterface を実装していること');
    }

    // -------------------------------------------------------------------------
    // totalWeight
    // -------------------------------------------------------------------------

    public function test_total_weight_returns_initial_sum(): void
    {
        // Arrange
        $builder = new FenwickSelectorBuilder(FenwickTreeSelector::build([3, 5, 2]));

        // Act & Assert
        $this->assertSame(10, $builder->totalWeight(), '初期 totalWeight が weights の合計と等しいこと');
    }

    public function test_total_weight_decreases_after_subtract(): void
    {
        // Arrange
        $builder = new FenwickSelectorBuilder(FenwickTreeSelector::build([3, 5, 2]));

        // Act
        $builder->subtract(0); // weight=3 を差し引く

        // Assert
        $this->assertSame(7, $builder->totalWeight(), 'subtract 後に totalWeight が weight 分だけ減ること');
    }

    public function test_total_weight_reaches_zero_when_all_subtracted(): void
    {
        // Arrange
        $builder = new FenwickSelectorBuilder(FenwickTreeSelector::build([3, 5, 2]));

        // Act
        $builder->subtract(0);
        $builder->subtract(1);
        $builder->subtract(2);

        // Assert
        $this->assertSame(0, $builder->totalWeight(), '全アイテムを subtract すると totalWeight が 0 になること');
    }

    // -------------------------------------------------------------------------
    // currentSelector
    // -------------------------------------------------------------------------

    public function test_current_selector_returns_same_instance(): void
    {
        // Arrange
        $selector = FenwickTreeSelector::build([1, 2, 3]);
        $builder  = new FenwickSelectorBuilder($selector);

        // Act & Assert
        $this->assertSame($selector, $builder->currentSelector(), 'currentSelector() がコンストラクタに渡した FenwickTreeSelector を返すこと');
    }

    // -------------------------------------------------------------------------
    // subtract → pick に即時反映（ペアリング契約）
    // -------------------------------------------------------------------------

    public function test_subtract_excludes_index_from_future_picks(): void
    {
        // Arrange — weights=[10, 10, 10]、index=1 を差し引く
        $selector = FenwickTreeSelector::build([10, 10, 10]);
        $builder  = new FenwickSelectorBuilder($selector);
        $builder->subtract(1);

        $randomizer = new SeededRandomizer(42);
        $seen       = [];

        // Act — 300 回 pick して index=1 が出ないことを確認
        for ($i = 0; $i < 300; $i++) {
            $seen[$builder->currentSelector()->pick($randomizer)] = true;
        }

        // Assert
        $this->assertArrayNotHasKey(1, $seen, 'subtract した index=1 が pick() で選ばれないこと');
        $this->assertArrayHasKey(0, $seen, 'index=0 は引き続き選ばれること');
        $this->assertArrayHasKey(2, $seen, 'index=2 は引き続き選ばれること');
    }

    public function test_subtract_is_immediately_reflected_without_rebuild(): void
    {
        // Arrange — subtract 後に currentSelector() を再取得せず同一参照で確認
        $selector = FenwickTreeSelector::build([1, 1]);
        $builder  = new FenwickSelectorBuilder($selector);
        $pickerBefore = $builder->currentSelector(); // subtract 前に参照を取得

        // Act
        $builder->subtract(0);
        $pickerAfter = $builder->currentSelector(); // subtract 後

        // Assert — 同一インスタンスであり、subtract が即座に反映されている
        $this->assertSame($pickerBefore, $pickerAfter, 'subtract 前後で currentSelector() が同一インスタンスを返すこと（rebuild なし）');
        $this->assertSame(1, $builder->totalWeight(), 'subtract 後の totalWeight が正しいこと');
    }
}
