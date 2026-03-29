<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit\Filter;

use PHPUnit\Framework\TestCase;
use WeightedSample\Filter\ItemFilterInterface;
use WeightedSample\Filter\PositiveValueFilter;

class PositiveValueFilterTest extends TestCase
{
    public function test_implements_item_filter_interface(): void
    {
        // Arrange & Act
        $filter = new PositiveValueFilter();

        // Assert
        $this->assertInstanceOf(ItemFilterInterface::class, $filter, 'PositiveValueFilter が ItemFilterInterface を実装していること');
    }

    public function test_accepts_positive_weight_without_count(): void
    {
        // Arrange
        $filter = new PositiveValueFilter();

        // Act & Assert
        $this->assertTrue($filter->accepts('item', 1, null), 'weight=1, count=null のアイテムが受け入れられること');
        $this->assertTrue($filter->accepts('item', 100, null), 'weight=100, count=null のアイテムが受け入れられること');
    }

    public function test_accepts_positive_weight_and_positive_count(): void
    {
        // Arrange
        $filter = new PositiveValueFilter();

        // Act & Assert
        $this->assertTrue($filter->accepts('item', 10, 1), 'weight=10, count=1 のアイテムが受け入れられること');
        $this->assertTrue($filter->accepts('item', 10, 99), 'weight=10, count=99 のアイテムが受け入れられること');
    }

    public function test_rejects_zero_weight(): void
    {
        // Arrange
        $filter = new PositiveValueFilter();

        // Act & Assert
        $this->assertFalse($filter->accepts('item', 0, null), 'weight=0 のアイテムが除外されること');
        $this->assertFalse($filter->accepts('item', 0, 5), 'weight=0, count=5 のアイテムが除外されること');
    }

    public function test_rejects_negative_weight(): void
    {
        // Arrange
        $filter = new PositiveValueFilter();

        // Act & Assert
        $this->assertFalse($filter->accepts('item', -1, null), 'weight=-1 のアイテムが除外されること');
    }

    public function test_rejects_zero_count(): void
    {
        // Arrange
        $filter = new PositiveValueFilter();

        // Act & Assert
        $this->assertFalse($filter->accepts('item', 10, 0), 'weight=10, count=0 のアイテムが除外されること');
    }

    public function test_rejects_negative_count(): void
    {
        // Arrange
        $filter = new PositiveValueFilter();

        // Act & Assert
        $this->assertFalse($filter->accepts('item', 10, -1), 'weight=10, count=-1 のアイテムが除外されること');
    }
}
