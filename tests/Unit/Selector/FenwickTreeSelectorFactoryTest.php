<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit\Selector;

use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;
use WeightedSample\Selector\FenwickTreeSelector;
use WeightedSample\Selector\FenwickTreeSelectorFactory;
use WeightedSample\SelectorFactoryInterface;
use WeightedSample\Tests\Support\RandomizerHelpers;

class FenwickTreeSelectorFactoryTest extends TestCase
{
    use RandomizerHelpers;

    // -------------------------------------------------------------------------
    // インターフェース実装
    // -------------------------------------------------------------------------

    public function test_implements_selector_factory_interface(): void
    {
        // Arrange & Act
        $factory = new FenwickTreeSelectorFactory();

        // Assert
        $this->assertInstanceOf(SelectorFactoryInterface::class, $factory, 'FenwickTreeSelectorFactory が SelectorFactoryInterface を実装していること');
    }

    public function test_create_returns_fenwick_tree_selector(): void
    {
        // Arrange
        $factory = new FenwickTreeSelectorFactory();

        // Act
        $selector = $factory->create([3, 5, 2]);

        // Assert
        $this->assertInstanceOf(FenwickTreeSelector::class, $selector, 'create() が FenwickTreeSelector を返すこと');
    }

    // -------------------------------------------------------------------------
    // バリデーション委譲
    // -------------------------------------------------------------------------

    public function test_create_throws_on_empty_weights(): void
    {
        // Arrange
        $factory = new FenwickTreeSelectorFactory();

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $factory->create([]);
    }

    public function test_create_throws_on_non_positive_weight(): void
    {
        // Arrange
        $factory = new FenwickTreeSelectorFactory();

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $factory->create([0, 10]);
    }

    public function test_create_throws_on_overflow(): void
    {
        // Arrange
        $factory = new FenwickTreeSelectorFactory();
        $half    = intdiv(\PHP_INT_MAX, 2) + 1;

        // Act & Assert
        $this->expectException(OverflowException::class);
        $factory->create([$half, $half]);
    }

    // -------------------------------------------------------------------------
    // 動作
    // -------------------------------------------------------------------------

    public function test_created_selector_picks_index_within_range(): void
    {
        // Arrange
        $factory    = new FenwickTreeSelectorFactory();
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
        $factory = new FenwickTreeSelectorFactory();

        // Act
        $selectorA = $factory->create([1, 9]);
        $selectorB = $factory->create([9, 1]);

        // Assert
        $this->assertNotSame($selectorA, $selectorB, 'create() を複数回呼ぶと独立したインスタンスが返ること');
    }

}
