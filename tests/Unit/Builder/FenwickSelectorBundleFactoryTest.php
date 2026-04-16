<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit\Builder;

use InvalidArgumentException;
use LogicException;
use OverflowException;
use PHPUnit\Framework\TestCase;
use WeightedSample\Builder\FenwickSelectorBundleFactory;
use WeightedSample\Builder\SelectorBuilderInterface;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\SelectorBundleFactoryInterface;

class FenwickSelectorBundleFactoryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // インターフェース実装
    // -------------------------------------------------------------------------

    public function test_implements_selector_bundle_factory_interface(): void
    {
        // Arrange & Act
        $factory = new FenwickSelectorBundleFactory();

        // Assert
        $this->assertInstanceOf(SelectorBundleFactoryInterface::class, $factory, 'FenwickSelectorBundleFactory が SelectorBundleFactoryInterface を実装していること');
    }

    public function test_create_returns_selector_builder_interface(): void
    {
        // Arrange
        $factory = new FenwickSelectorBundleFactory();

        // Act
        $builder = $factory->create([3, 5, 2]);

        // Assert
        $this->assertInstanceOf(SelectorBuilderInterface::class, $builder, 'create() が SelectorBuilderInterface を返すこと');
    }

    // -------------------------------------------------------------------------
    // バリデーション委譲
    // -------------------------------------------------------------------------

    public function test_create_throws_on_empty_weights(): void
    {
        // Arrange
        $factory = new FenwickSelectorBundleFactory();

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $factory->create([]);
    }

    public function test_create_throws_on_non_positive_weight(): void
    {
        // Arrange
        $factory = new FenwickSelectorBundleFactory();

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $factory->create([0, 10]);
    }

    public function test_create_throws_on_overflow(): void
    {
        // Arrange
        $factory = new FenwickSelectorBundleFactory();
        $half    = intdiv(\PHP_INT_MAX, 2) + 1;

        // Act & Assert
        $this->expectException(OverflowException::class);
        $factory->create([$half, $half]);
    }

    // -------------------------------------------------------------------------
    // ペアリング契約 — subtract() が currentSelector()->pick() に即時反映
    // -------------------------------------------------------------------------

    public function test_subtract_is_reflected_in_current_selector(): void
    {
        // Arrange — weights=[10, 10, 10]、index=0 を差し引く
        $factory = new FenwickSelectorBundleFactory();
        $builder = $factory->create([10, 10, 10]);

        $builder->subtract(0);

        $randomizer = new SeededRandomizer(42);
        $seen       = [];

        // Act — 300 回 pick して index=0 が出ないことを確認
        for ($i = 0; $i < 300; $i++) {
            $seen[$builder->currentSelector()->pick($randomizer)] = true;
        }

        // Assert
        $this->assertArrayNotHasKey(0, $seen, 'subtract した index=0 が currentSelector()->pick() で選ばれないこと');
        $this->assertArrayHasKey(1, $seen, 'index=1 は引き続き選ばれること');
        $this->assertArrayHasKey(2, $seen, 'index=2 は引き続き選ばれること');
    }

    public function test_total_weight_updates_after_subtract(): void
    {
        // Arrange
        $factory = new FenwickSelectorBundleFactory();
        $builder = $factory->create([5, 5]);

        // Act — subtract して totalWeight で確認
        $builder->subtract(0); // weight=5 を差し引く

        // Assert — totalWeight が即時更新されていること
        $this->assertSame(5, $builder->totalWeight(), 'subtract 後に totalWeight が正しく更新されること');
    }

    public function test_current_selector_throws_logic_exception_when_all_subtracted(): void
    {
        // Arrange — 1アイテムをすべて差し引いて totalWeight=0 にする
        $factory = new FenwickSelectorBundleFactory();
        $builder = $factory->create([5]);
        $builder->subtract(0);

        // Act & Assert — totalWeight=0 の状態で currentSelector() を呼ぶと LogicException
        $this->expectException(LogicException::class);
        $builder->currentSelector();
    }

    public function test_each_create_call_returns_independent_builder(): void
    {
        // Arrange
        $factory = new FenwickSelectorBundleFactory();

        // Act
        $builderA = $factory->create([1, 9]);
        $builderB = $factory->create([1, 9]);

        $builderA->subtract(0);

        // Assert — builderA の操作が builderB に影響しないこと
        $this->assertSame(10, $builderB->totalWeight(), 'builderA の subtract が builderB に影響しないこと');
    }
}
