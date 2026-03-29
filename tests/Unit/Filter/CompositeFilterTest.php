<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit\Filter;

use PHPUnit\Framework\TestCase;
use WeightedSample\Filter\CompositeFilter;
use WeightedSample\Filter\ItemFilterInterface;
use WeightedSample\Filter\PositiveValueFilter;
use WeightedSample\Filter\StrictValueFilter;

class CompositeFilterTest extends TestCase
{
    public function test_implements_item_filter_interface(): void
    {
        // Arrange & Act
        $filter = new CompositeFilter([]);

        // Assert
        $this->assertInstanceOf(ItemFilterInterface::class, $filter, 'CompositeFilter が ItemFilterInterface を実装していること');
    }

    public function test_empty_filters_accepts_everything(): void
    {
        // Arrange
        $filter = new CompositeFilter([]);

        // Act & Assert
        $this->assertTrue($filter->accepts('item', 0, null), 'フィルターが空のとき常に true を返すこと');
    }

    public function test_accepts_when_all_filters_pass(): void
    {
        // Arrange
        $filter = new CompositeFilter([new PositiveValueFilter(), new PositiveValueFilter()]);

        // Act & Assert
        $this->assertTrue($filter->accepts('item', 10, 5), '全フィルターが通過したとき true を返すこと');
    }

    public function test_rejects_when_first_filter_fails(): void
    {
        // Arrange
        $alwaysReject = new class () implements ItemFilterInterface {
            public function accepts(mixed $item, int $weight, ?int $count): bool
            {
                return false;
            }
        };
        $filter = new CompositeFilter([$alwaysReject, new PositiveValueFilter()]);

        // Act & Assert
        $this->assertFalse($filter->accepts('item', 10, 5), '最初のフィルターが false のとき false を返すこと');
    }

    public function test_rejects_when_second_filter_fails(): void
    {
        // Arrange
        $alwaysReject = new class () implements ItemFilterInterface {
            public function accepts(mixed $item, int $weight, ?int $count): bool
            {
                return false;
            }
        };
        $filter = new CompositeFilter([new PositiveValueFilter(), $alwaysReject]);

        // Act & Assert
        $this->assertFalse($filter->accepts('item', 10, 5), '2番目のフィルターが false のとき false を返すこと');
    }

    public function test_propagates_exception_from_inner_filter(): void
    {
        // Arrange — StrictValueFilter のみ（PositiveValueFilter で短絡させない）
        $filter = new CompositeFilter([new StrictValueFilter()]);

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        $filter->accepts('item', 0, null);
    }

    public function test_accepts_with_three_filters_all_passing(): void
    {
        // Arrange
        $filter = new CompositeFilter([
            new PositiveValueFilter(),
            new PositiveValueFilter(),
            new PositiveValueFilter(),
        ]);

        // Act & Assert
        $this->assertTrue($filter->accepts('item', 10, 5), '3つのフィルターが全て通過したとき true を返すこと');
    }

    public function test_rejects_when_third_filter_fails(): void
    {
        // Arrange
        $alwaysReject = new class () implements ItemFilterInterface {
            public function accepts(mixed $item, int $weight, ?int $count): bool
            {
                return false;
            }
        };
        $filter = new CompositeFilter([new PositiveValueFilter(), new PositiveValueFilter(), $alwaysReject]);

        // Act & Assert
        $this->assertFalse($filter->accepts('item', 10, 5), '3番目のフィルターが false のとき false を返すこと');
    }

    public function test_short_circuits_on_first_rejection(): void
    {
        // Arrange — 1つ目が false を返したら2つ目の throw に到達しないこと
        $alwaysReject = new class () implements ItemFilterInterface {
            public function accepts(mixed $item, int $weight, ?int $count): bool
            {
                return false;
            }
        };
        $filter = new CompositeFilter([$alwaysReject, new StrictValueFilter()]);

        // Act & Assert — StrictValueFilter の throw に到達せず false が返ること
        $this->assertFalse($filter->accepts('item', 0, null), '短絡評価で1つ目の false が返ること');
    }
}
