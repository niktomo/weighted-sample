<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit\Builder;

use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;
use WeightedSample\Builder\RebuildSelectorBundleFactory;
use WeightedSample\Builder\SelectorBuilderInterface;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\AliasTableSelectorFactory;
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

    public function test_create_returns_selector_builder_interface(): void
    {
        // Arrange
        $factory = new RebuildSelectorBundleFactory();

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
        $factory = new RebuildSelectorBundleFactory();

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $factory->create([]);
    }

    public function test_create_throws_on_non_positive_weight(): void
    {
        // Arrange
        $factory = new RebuildSelectorBundleFactory();

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $factory->create([0, 10]);
    }

    public function test_create_throws_on_overflow(): void
    {
        // Arrange
        $factory = new RebuildSelectorBundleFactory();
        $half    = intdiv(\PHP_INT_MAX, 2) + 1;

        // Act & Assert
        $this->expectException(OverflowException::class);
        $factory->create([$half, $half]);
    }

    // -------------------------------------------------------------------------
    // subtract → currentSelector()->pick() に即時反映（元インデックスで返ること）
    // -------------------------------------------------------------------------

    public function test_subtract_is_reflected_in_current_selector(): void
    {
        // Arrange — weights=[10, 10, 10]、index=2 を差し引く
        $factory = new RebuildSelectorBundleFactory();
        $builder = $factory->create([10, 10, 10]);

        $builder->subtract(2);

        $randomizer = new SeededRandomizer(42);
        $seen       = [];

        // Act — subtract 後の currentSelector() で 300 回 pick して index=2 が出ないことを確認
        for ($i = 0; $i < 300; $i++) {
            $seen[$builder->currentSelector()->pick($randomizer)] = true;
        }

        // Assert
        $this->assertArrayNotHasKey(2, $seen, 'subtract した index=2 が currentSelector()->pick() で選ばれないこと');
        $this->assertArrayHasKey(0, $seen, 'index=0 は元インデックスで選ばれること');
        $this->assertArrayHasKey(1, $seen, 'index=1 は元インデックスで選ばれること');
    }

    public function test_each_create_call_returns_independent_builder(): void
    {
        // Arrange
        $factory = new RebuildSelectorBundleFactory();

        // Act
        $builderA = $factory->create([1, 9]);
        $builderB = $factory->create([1, 9]);

        $builderA->subtract(0);

        // Assert — builderA の操作が builderB に影響しないこと
        $this->assertSame(10, $builderB->totalWeight(), 'builderA の subtract が builderB に影響しないこと');
    }

    // -------------------------------------------------------------------------
    // AliasTableSelectorFactory — 非デフォルトファクトリーの注入
    // -------------------------------------------------------------------------

    public function test_alias_table_factory_excludes_subtracted_index(): void
    {
        // Arrange — AliasTableSelectorFactory を注入した RebuildSelectorBundleFactory
        $factory = new RebuildSelectorBundleFactory(new AliasTableSelectorFactory());
        $builder = $factory->create([10, 10, 10]);
        $builder->subtract(1);

        $randomizer = new SeededRandomizer(42);
        $seen       = [];

        // Act — subtract 後に 300 回 pick して index=1 が出ないことを確認
        for ($i = 0; $i < 300; $i++) {
            $seen[$builder->currentSelector()->pick($randomizer)] = true;
        }

        // Assert — AliasTableSelectorFactory でも元インデックスが正しく返ること
        $this->assertArrayNotHasKey(1, $seen, 'AliasTable: subtract した index=1 が選ばれないこと');
        $this->assertArrayHasKey(0, $seen, 'AliasTable: index=0 は元インデックスで選ばれること');
        $this->assertArrayHasKey(2, $seen, 'AliasTable: index=2 は元インデックスで選ばれること');
    }

    public function test_alias_table_factory_total_weight_after_subtract(): void
    {
        // Arrange
        $factory = new RebuildSelectorBundleFactory(new AliasTableSelectorFactory());
        $builder = $factory->create([5, 15, 10]);

        // Act
        $builder->subtract(1); // weight=15 を差し引く

        // Assert
        $this->assertSame(15, $builder->totalWeight(), 'AliasTable: subtract 後の totalWeight が正しく更新されること');
    }
}
