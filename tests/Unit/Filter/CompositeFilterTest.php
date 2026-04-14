<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit\Filter;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use WeightedSample\Filter\CompositeFilter;
use WeightedSample\Filter\CountedItemFilterInterface;
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

    public function test_implements_counted_item_filter_interface(): void
    {
        // Arrange & Act
        $filter = new CompositeFilter([]);

        // Assert
        $this->assertInstanceOf(CountedItemFilterInterface::class, $filter, 'CompositeFilter が CountedItemFilterInterface を実装していること');
    }

    public function test_empty_filters_accepts_everything(): void
    {
        // Arrange
        $filter = new CompositeFilter([]);

        // Act & Assert
        $this->assertTrue($filter->accepts('item', 1), 'フィルターが空のとき accepts が true を返すこと');
        $this->assertTrue($filter->acceptsWithCount('item', 1, 1), 'フィルターが空のとき acceptsWithCount が true を返すこと');
    }

    public function test_accepts_when_all_filters_pass(): void
    {
        // Arrange
        $filter = new CompositeFilter([new PositiveValueFilter(), new PositiveValueFilter()]);

        // Act & Assert
        $this->assertTrue($filter->accepts('item', 10), '全フィルターが通過したとき accepts が true を返すこと');
    }

    public function test_accepts_with_count_when_all_filters_pass(): void
    {
        // Arrange
        $filter = new CompositeFilter([new PositiveValueFilter(), new PositiveValueFilter()]);

        // Act & Assert
        $this->assertTrue($filter->acceptsWithCount('item', 10, 5), '全フィルターが通過したとき acceptsWithCount が true を返すこと');
    }

    public function test_rejects_when_first_filter_fails(): void
    {
        // Arrange
        $alwaysReject = new class () implements ItemFilterInterface {
            public function accepts(mixed $item, int $weight): bool
            {
                return false;
            }
        };
        $filter = new CompositeFilter([$alwaysReject, new PositiveValueFilter()]);

        // Act & Assert
        $this->assertFalse($filter->accepts('item', 10), '最初のフィルターが false のとき false を返すこと');
    }

    public function test_rejects_when_second_filter_fails(): void
    {
        // Arrange
        $alwaysReject = new class () implements ItemFilterInterface {
            public function accepts(mixed $item, int $weight): bool
            {
                return false;
            }
        };
        $filter = new CompositeFilter([new PositiveValueFilter(), $alwaysReject]);

        // Act & Assert
        $this->assertFalse($filter->accepts('item', 10), '2番目のフィルターが false のとき false を返すこと');
    }

    public function test_propagates_exception_from_inner_filter(): void
    {
        // Arrange — StrictValueFilter のみ（PositiveValueFilter で短絡させない）
        $filter = new CompositeFilter([new StrictValueFilter()]);

        // Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        $filter->accepts('item', 0);
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
        $this->assertTrue($filter->accepts('item', 10), '3つのフィルターが全て通過したとき true を返すこと');
    }

    public function test_rejects_when_third_filter_fails(): void
    {
        // Arrange
        $alwaysReject = new class () implements ItemFilterInterface {
            public function accepts(mixed $item, int $weight): bool
            {
                return false;
            }
        };
        $filter = new CompositeFilter([new PositiveValueFilter(), new PositiveValueFilter(), $alwaysReject]);

        // Act & Assert
        $this->assertFalse($filter->accepts('item', 10), '3番目のフィルターが false のとき false を返すこと');
    }

    public function test_short_circuits_on_first_rejection(): void
    {
        // Arrange — 1つ目が false を返したら2つ目の throw に到達しないこと
        $alwaysReject = new class () implements ItemFilterInterface {
            public function accepts(mixed $item, int $weight): bool
            {
                return false;
            }
        };
        $filter = new CompositeFilter([$alwaysReject, new StrictValueFilter()]);

        // Act & Assert — StrictValueFilter の throw に到達せず false が返ること
        $this->assertFalse($filter->accepts('item', 0), '短絡評価で1つ目の false が返ること');
    }

    public function test_accepts_with_count_delegates_to_counted_filter(): void
    {
        // Arrange — CountedItemFilterInterface を実装するフィルターが count を検証すること
        $filter = new CompositeFilter([new PositiveValueFilter()]);

        // Act & Assert
        $this->assertFalse($filter->acceptsWithCount('item', 10, 0), 'count=0 のとき CountedItemFilterInterface の実装が false を返すこと');
        $this->assertTrue($filter->acceptsWithCount('item', 10, 1), 'count=1 のとき CountedItemFilterInterface の実装が true を返すこと');
    }

    public function test_accepts_with_count_falls_back_to_accepts_for_non_counted_filter(): void
    {
        // Arrange — ItemFilterInterface のみ実装（CountedItemFilterInterface は未実装）のフィルター
        $weightOnlyFilter = new class () implements ItemFilterInterface {
            public function accepts(mixed $item, int $weight): bool
            {
                return $weight > 5;
            }
        };
        $filter = new CompositeFilter([$weightOnlyFilter]);

        // Act & Assert — count は無視され weight のみで判定されること
        $this->assertTrue($filter->acceptsWithCount('item', 10, 0), 'CountedItemFilterInterface 未実装のフィルターは weight のみで判定されること');
        $this->assertFalse($filter->acceptsWithCount('item', 3, 99), 'weight が閾値未満のとき false を返すこと');
    }

    public function test_accepts_with_count_uses_accepts_with_count_not_accepts_for_counted_filter(): void
    {
        // Arrange — accepts() は常に true だが acceptsWithCount() は count > 5 のときのみ true を返すフィルター
        // このテストにより「CountedItemFilterInterface 実装フィルターに対して acceptsWithCount() が呼ばれる」ことを証明する
        $countGatedFilter = new class () implements CountedItemFilterInterface {
            public function accepts(mixed $item, int $weight): bool
            {
                return true; // weight だけ見れば常に通過
            }

            public function acceptsWithCount(mixed $item, int $weight, int $count): bool
            {
                return $count > 5; // count が 5 超のときのみ通過
            }
        };
        $filter = new CompositeFilter([$countGatedFilter]);

        // Act & Assert — acceptsWithCount() が委譲先で呼ばれていること（accepts() へのフォールバックではないこと）
        $this->assertFalse(
            $filter->acceptsWithCount('item', 10, 3),
            'count=3 のとき CountedItemFilterInterface の acceptsWithCount() が呼ばれて false を返すこと（accepts() へのフォールバックなら true になる）',
        );
        $this->assertTrue(
            $filter->acceptsWithCount('item', 10, 10),
            'count=10 のとき CountedItemFilterInterface の acceptsWithCount() が呼ばれて true を返すこと',
        );
    }

    public function test_accepts_with_count_mixes_counted_and_non_counted_filters(): void
    {
        // Arrange — CountedItemFilterInterface + ItemFilterInterface を混在させる
        $countGatedFilter = new class () implements CountedItemFilterInterface {
            public function accepts(mixed $item, int $weight): bool
            {
                return true;
            }

            public function acceptsWithCount(mixed $item, int $weight, int $count): bool
            {
                return $count > 0;
            }
        };
        $weightGatedFilter = new class () implements ItemFilterInterface {
            public function accepts(mixed $item, int $weight): bool
            {
                return $weight > 0;
            }
        };
        $filter = new CompositeFilter([$countGatedFilter, $weightGatedFilter]);

        // Act & Assert
        $this->assertTrue($filter->acceptsWithCount('item', 10, 1), 'weight>0 かつ count>0 のとき true を返すこと');
        $this->assertFalse($filter->acceptsWithCount('item', 10, 0), 'count=0 のとき CountedItemFilterInterface が false を返すこと');
        $this->assertFalse($filter->acceptsWithCount('item', 0, 1), 'weight=0 のとき ItemFilterInterface が false を返すこと');
    }
}
