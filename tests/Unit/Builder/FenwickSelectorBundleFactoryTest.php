<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use WeightedSample\Builder\FenwickSelectorBundleFactory;
use WeightedSample\Builder\SelectorBundle;
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

    public function test_create_returns_selector_bundle(): void
    {
        // Arrange
        $factory = new FenwickSelectorBundleFactory();

        // Act
        $bundle = $factory->create([3, 5, 2]);

        // Assert
        $this->assertInstanceOf(SelectorBundle::class, $bundle, 'create() が SelectorBundle を返すこと');
    }

    // -------------------------------------------------------------------------
    // バリデーション委譲
    // -------------------------------------------------------------------------

    public function test_create_throws_on_empty_weights(): void
    {
        // Arrange
        $factory = new FenwickSelectorBundleFactory();

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $factory->create([]);
    }

    public function test_create_throws_on_non_positive_weight(): void
    {
        // Arrange
        $factory = new FenwickSelectorBundleFactory();

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $factory->create([0, 10]);
    }

    public function test_create_throws_on_overflow(): void
    {
        // Arrange
        $factory = new FenwickSelectorBundleFactory();
        $half    = intdiv(\PHP_INT_MAX, 2) + 1;

        // Act & Assert
        $this->expectException(\OverflowException::class);
        $factory->create([$half, $half]);
    }

    // -------------------------------------------------------------------------
    // ペアリング契約 — builder.subtract() が bundle.selector.pick() に即時反映
    // -------------------------------------------------------------------------

    public function test_subtract_on_builder_is_reflected_in_selector(): void
    {
        // Arrange — weights=[10, 10, 10]、index=0 を差し引く
        $factory = new FenwickSelectorBundleFactory();
        $bundle  = $factory->create([10, 10, 10]);

        $bundle->builder->subtract(0);

        $randomizer = new SeededRandomizer(42);
        $seen       = [];

        // Act — 300 回 pick して index=0 が出ないことを確認
        for ($i = 0; $i < 300; $i++) {
            $seen[$bundle->selector->pick($randomizer)] = true;
        }

        // Assert
        $this->assertArrayNotHasKey(0, $seen, 'subtract した index=0 が bundle->selector->pick() で選ばれないこと');
        $this->assertArrayHasKey(1, $seen, 'index=1 は引き続き選ばれること');
        $this->assertArrayHasKey(2, $seen, 'index=2 は引き続き選ばれること');
    }

    public function test_bundle_selector_and_builder_share_same_state(): void
    {
        // Arrange
        $factory = new FenwickSelectorBundleFactory();
        $bundle  = $factory->create([5, 5]);

        // Act — builder 経由で subtract、builder.totalWeight で確認
        $bundle->builder->subtract(0); // weight=5 を差し引く

        // Assert — totalWeight が即時更新されていること
        $this->assertSame(5, $bundle->builder->totalWeight(), 'subtract 後に totalWeight が正しく更新されること');
    }

    public function test_each_create_call_returns_independent_bundle(): void
    {
        // Arrange
        $factory = new FenwickSelectorBundleFactory();

        // Act
        $bundleA = $factory->create([1, 9]);
        $bundleB = $factory->create([1, 9]);

        $bundleA->builder->subtract(0);

        // Assert — bundleA の操作が bundleB に影響しないこと
        $this->assertSame(10, $bundleB->builder->totalWeight(), 'bundleA の subtract が bundleB に影響しないこと');
    }
}
