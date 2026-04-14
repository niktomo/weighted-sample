<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit\Selector;

use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;
use WeightedSample\Selector\AliasTableSelector;
use WeightedSample\Selector\AliasTableSelectorFactory;
use WeightedSample\SelectorFactoryInterface;
use WeightedSample\Tests\Support\RandomizerHelpers;

class AliasTableSelectorFactoryTest extends TestCase
{
    use RandomizerHelpers;

    // -------------------------------------------------------------------------
    // インターフェース実装
    // -------------------------------------------------------------------------

    public function test_implements_selector_factory_interface(): void
    {
        // Arrange & Act
        $factory = new AliasTableSelectorFactory();

        // Assert
        $this->assertInstanceOf(SelectorFactoryInterface::class, $factory, 'AliasTableSelectorFactory が SelectorFactoryInterface を実装していること');
    }

    public function test_create_returns_alias_table_selector(): void
    {
        // Arrange
        $factory = new AliasTableSelectorFactory();

        // Act
        $selector = $factory->create([3, 5, 2]);

        // Assert
        $this->assertInstanceOf(AliasTableSelector::class, $selector, 'create() が AliasTableSelector を返すこと');
    }

    // -------------------------------------------------------------------------
    // バリデーション委譲
    // -------------------------------------------------------------------------

    public function test_create_throws_on_empty_weights(): void
    {
        // Arrange
        $factory = new AliasTableSelectorFactory();

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $factory->create([]);
    }

    public function test_create_throws_on_non_positive_weight(): void
    {
        // Arrange
        $factory = new AliasTableSelectorFactory();

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $factory->create([0, 10]);
    }

    public function test_create_throws_on_overflow(): void
    {
        // Arrange — n=2, W≈PHP_INT_MAX → n×W > PHP_INT_MAX
        // (AliasTableSelector は n×W ≤ PHP_INT_MAX を要求する)
        $factory = new AliasTableSelectorFactory();
        $half    = intdiv(\PHP_INT_MAX, 2);

        // Act & Assert
        $this->expectException(OverflowException::class);
        $factory->create([$half, $half]);
    }

    public function test_create_throws_when_n_times_total_weight_exceeds_int_max(): void
    {
        // Arrange — AliasTableSelector は n×W ≤ PHP_INT_MAX を要求する。
        // n=10, w = PHP_INT_MAX/11 → W = 10w ≈ 10/11 × PHP_INT_MAX (fits)
        //                           → n×W = 100w ≈ 100/11 × PHP_INT_MAX (overflows)
        $factory = new AliasTableSelectorFactory();
        $w       = intdiv(\PHP_INT_MAX, 11);  // W = 10w < PHP_INT_MAX; n×W > PHP_INT_MAX

        // Act & Assert
        $this->expectException(OverflowException::class);
        $factory->create(array_fill(0, 10, $w));
    }

    // -------------------------------------------------------------------------
    // 動作
    // -------------------------------------------------------------------------

    public function test_created_selector_picks_index_within_range(): void
    {
        // Arrange
        $factory    = new AliasTableSelectorFactory();
        $selector   = $factory->create([3, 5, 2]);
        $randomizer = $this->fixedRandomizer(0);

        // Act
        $index = $selector->pick($randomizer);

        // Assert
        $this->assertGreaterThanOrEqual(0, $index, 'pick() が 0 以上のインデックスを返すこと');
        $this->assertLessThan(3, $index, 'pick() が weights の長さ未満のインデックスを返すこと');
    }

    public function test_each_create_call_returns_independent_selector(): void
    {
        // Arrange
        $factory = new AliasTableSelectorFactory();

        // Act
        $selectorA = $factory->create([1, 9]);
        $selectorB = $factory->create([9, 1]);

        // Assert
        $this->assertNotSame($selectorA, $selectorB, 'create() を複数回呼ぶと独立したインスタンスが返ること');
    }

}
