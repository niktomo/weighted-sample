<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use WeightedSample\Builder\RebuildSelectorBundleFactory;
use WeightedSample\Builder\SelectorBundle;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\SelectorBundleFactoryInterface;

class RebuildSelectorBundleFactoryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // インターフェース実装
    // -------------------------------------------------------------------------

    public function test_implements_selector_bundle_factory_interface(): void
    {
        // Arrange & Act
        $factory = new RebuildSelectorBundleFactory();

        // Assert
        $this->assertInstanceOf(SelectorBundleFactoryInterface::class, $factory, 'RebuildSelectorBundleFactory が SelectorBundleFactoryInterface を実装していること');
    }

    public function test_create_returns_selector_bundle(): void
    {
        // Arrange
        $factory = new RebuildSelectorBundleFactory();

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
        $factory = new RebuildSelectorBundleFactory();

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $factory->create([]);
    }

    public function test_create_throws_on_non_positive_weight(): void
    {
        // Arrange
        $factory = new RebuildSelectorBundleFactory();

        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $factory->create([0, 10]);
    }

    public function test_create_throws_on_overflow(): void
    {
        // Arrange
        $factory = new RebuildSelectorBundleFactory();
        $half    = intdiv(\PHP_INT_MAX, 2) + 1;

        // Act & Assert
        $this->expectException(\OverflowException::class);
        $factory->create([$half, $half]);
    }

    // -------------------------------------------------------------------------
    // subtract → pick に即時反映（元インデックスで返ること）
    // -------------------------------------------------------------------------

    public function test_subtract_on_builder_is_reflected_in_current_selector(): void
    {
        // Arrange — weights=[10, 10, 10]、index=2 を差し引く
        $factory = new RebuildSelectorBundleFactory();
        $bundle  = $factory->create([10, 10, 10]);

        $bundle->builder->subtract(2);

        $randomizer = new SeededRandomizer(42);
        $seen       = [];

        // Act — subtract 後の currentSelector() で 300 回 pick して index=2 が出ないことを確認
        for ($i = 0; $i < 300; $i++) {
            $seen[$bundle->builder->currentSelector()->pick($randomizer)] = true;
        }

        // Assert
        $this->assertArrayNotHasKey(2, $seen, 'subtract した index=2 が builder->currentSelector()->pick() で選ばれないこと');
        $this->assertArrayHasKey(0, $seen, 'index=0 は元インデックスで選ばれること');
        $this->assertArrayHasKey(1, $seen, 'index=1 は元インデックスで選ばれること');
    }

    public function test_each_create_call_returns_independent_bundle(): void
    {
        // Arrange
        $factory = new RebuildSelectorBundleFactory();

        // Act
        $bundleA = $factory->create([1, 9]);
        $bundleB = $factory->create([1, 9]);

        $bundleA->builder->subtract(0);

        // Assert — bundleA の操作が bundleB に影響しないこと
        $this->assertSame(10, $bundleB->builder->totalWeight(), 'bundleA の subtract が bundleB に影響しないこと');
    }
}
