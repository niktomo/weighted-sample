<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit\Filter;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WeightedSample\Filter\CountedItemFilterInterface;
use WeightedSample\Filter\ItemFilterInterface;
use WeightedSample\Filter\StrictValueFilter;

class StrictValueFilterTest extends TestCase
{
    public function test_implements_item_filter_interface(): void
    {
        // Arrange & Act
        $filter = new StrictValueFilter();

        // Assert
        $this->assertInstanceOf(ItemFilterInterface::class, $filter, 'StrictValueFilter が ItemFilterInterface を実装していること');
    }

    public function test_implements_counted_item_filter_interface(): void
    {
        // Arrange & Act
        $filter = new StrictValueFilter();

        // Assert
        $this->assertInstanceOf(CountedItemFilterInterface::class, $filter, 'StrictValueFilter が CountedItemFilterInterface を実装していること');
    }

    public function test_accepts_positive_weight(): void
    {
        // Arrange
        $filter = new StrictValueFilter();

        // Act & Assert
        $this->assertTrue($filter->accepts('item', 1), 'weight=1 のアイテムが受け入れられること');
    }

    public function test_accepts_with_count_positive_weight_and_positive_count(): void
    {
        // Arrange
        $filter = new StrictValueFilter();

        // Act & Assert
        $this->assertTrue($filter->acceptsWithCount('item', 10, 3), 'weight=10, count=3 のアイテムが受け入れられること');
    }

    public function test_throws_on_zero_weight(): void
    {
        // Arrange
        $filter = new StrictValueFilter();

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('weight');

        // Act
        $filter->accepts('item', 0);
    }

    public function test_throws_on_negative_weight(): void
    {
        // Arrange
        $filter = new StrictValueFilter();

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('weight');

        // Act
        $filter->accepts('item', -1);
    }

    public function test_accepts_with_count_throws_on_zero_weight(): void
    {
        // Arrange
        $filter = new StrictValueFilter();

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('weight');

        // Act
        $filter->acceptsWithCount('item', 0, 1);
    }

    public function test_accepts_with_count_throws_on_zero_count(): void
    {
        // Arrange
        $filter = new StrictValueFilter();

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('count');

        // Act
        $filter->acceptsWithCount('item', 10, 0);
    }

    public function test_accepts_with_count_throws_on_negative_count(): void
    {
        // Arrange
        $filter = new StrictValueFilter();

        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('count');

        // Act
        $filter->acceptsWithCount('item', 10, -1);
    }
}
