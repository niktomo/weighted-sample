<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Filter\StrictValueFilter;
use WeightedSample\Pool\BoxPool;
use WeightedSample\Pool\ExhaustiblePoolInterface;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Selector\AliasTableSelector;

class BoxPoolTest extends TestCase
{
    // -------------------------------------------------------------------------
    // 構築
    // -------------------------------------------------------------------------

    public function test_of_creates_instance(): void
    {
        // Arrange & Act
        $pool = BoxPool::of(
            [['id' => 1, 'weight' => 10, 'count' => 3]],
            fn ($i) => $i['weight'],
            fn ($i) => $i['count'],
        );

        // Assert
        $this->assertInstanceOf(BoxPool::class, $pool, 'BoxPool インスタンスが生成されること');
        $this->assertInstanceOf(ExhaustiblePoolInterface::class, $pool, 'BoxPool が ExhaustiblePoolInterface を実装していること');
    }

    public function test_throws_on_empty_items(): void
    {
        // Assert
        $this->expectException(EmptyPoolException::class);

        // Act
        BoxPool::of([], fn ($i) => $i['weight'], fn ($i) => $i['count']);
    }

    public function test_zero_count_items_are_excluded_by_default(): void
    {
        // Arrange — count=0 のアイテムはデフォルトフィルターで除外される
        $items = [
            ['id' => 1, 'weight' => 10, 'count' => 0],
            ['id' => 2, 'weight' => 90, 'count' => 1],
        ];
        $pool = BoxPool::of($items, fn ($i) => $i['weight'], fn ($i) => $i['count'], randomizer: $this->fixedRandomizer(0));

        // Act & Assert — count=0 の id=1 は除外され id=2 のみ残ること
        $this->assertSame(2, $pool->draw()['id'], 'count=0 のアイテムが除外され残ったアイテムが返ること');
    }

    public function test_throws_when_all_items_are_filtered_out(): void
    {
        // Arrange — 全アイテムが count=0
        $items = [
            ['id' => 1, 'weight' => 10, 'count' => 0],
            ['id' => 2, 'weight' => 90, 'count' => 0],
        ];

        // Assert
        $this->expectException(EmptyPoolException::class);

        // Act
        BoxPool::of($items, fn ($i) => $i['weight'], fn ($i) => $i['count']);
    }

    public function test_strict_filter_throws_on_zero_count(): void
    {
        // Arrange
        $items = [['id' => 1, 'weight' => 10, 'count' => 0]];

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        BoxPool::of($items, fn ($i) => $i['weight'], fn ($i) => $i['count'], filter: new StrictValueFilter());
    }

    // -------------------------------------------------------------------------
    // draw() — count の消費
    // -------------------------------------------------------------------------

    public function test_draw_returns_item(): void
    {
        // Arrange
        $items = [
            ['id' => 1, 'weight' => 10, 'count' => 2],
            ['id' => 2, 'weight' => 90, 'count' => 2],
        ];
        $pool = BoxPool::of($items, fn ($i) => $i['weight'], fn ($i) => $i['count']);

        // Act
        $item = $pool->draw();

        // Assert
        $this->assertContains($item['id'], [1, 2], 'draw() がプール内のアイテムを返すこと');
    }

    public function test_item_is_excluded_after_count_exhausted(): void
    {
        // Arrange — rand=0 で常に先頭アイテムを引く
        // items: [id=1 count=1, id=2 count=3]
        // 1回目: id=1 が引かれ count=0 → 除外
        // 2〜4回目: id=2 のみ残る
        $items = [
            ['id' => 1, 'weight' => 10, 'count' => 1],
            ['id' => 2, 'weight' => 90, 'count' => 3],
        ];
        $pool = BoxPool::of($items, fn ($i) => $i['weight'], fn ($i) => $i['count'], randomizer: $this->fixedRandomizer(0));

        // Act
        $first  = $pool->draw()['id'];
        $second = $pool->draw()['id'];
        $third  = $pool->draw()['id'];
        $fourth = $pool->draw()['id'];

        // Assert
        $this->assertSame(1, $first, '1回目: count=1 のアイテムが引かれること');
        $this->assertSame(2, $second, '2回目: count=0 になったアイテムは除外され id=2 が引かれること');
        $this->assertSame(2, $third, '3回目: id=2 が引かれること');
        $this->assertSame(2, $fourth, '4回目: id=2 が引かれること');
    }

    // -------------------------------------------------------------------------
    // isEmpty()
    // -------------------------------------------------------------------------

    public function test_is_not_empty_initially(): void
    {
        // Arrange
        $pool = BoxPool::of(
            [['id' => 1, 'weight' => 10, 'count' => 1]],
            fn ($i) => $i['weight'],
            fn ($i) => $i['count'],
        );

        // Assert
        $this->assertFalse($pool->isEmpty(), '構築直後は isEmpty() が false であること');
    }

    public function test_is_empty_after_all_counts_exhausted(): void
    {
        // Arrange — rand=0 で常に先頭を引く
        $items = [
            ['id' => 1, 'weight' => 10, 'count' => 2],
            ['id' => 2, 'weight' => 90, 'count' => 1],
        ];
        $pool = BoxPool::of($items, fn ($i) => $i['weight'], fn ($i) => $i['count'], randomizer: $this->fixedRandomizer(0));

        // Act — 計3回（count合計）引き切る
        $pool->draw(); // id=1, count=1
        $pool->draw(); // id=1, count=0 → 除外
        $pool->draw(); // id=2, count=0 → 除外

        // Assert
        $this->assertTrue($pool->isEmpty(), '全 count を消費した後 isEmpty() が true になること');
    }

    public function test_draw_throws_on_empty_pool(): void
    {
        // Arrange
        $pool = BoxPool::of(
            [['id' => 1, 'weight' => 10, 'count' => 1]],
            fn ($i) => $i['weight'],
            fn ($i) => $i['count'],
        );
        $pool->draw();

        // Assert
        $this->expectException(EmptyPoolException::class);

        // Act
        $pool->draw();
    }

    // -------------------------------------------------------------------------
    // selectorClass — セレクター差し替え
    // -------------------------------------------------------------------------

    public function test_alias_table_selector_draws_valid_item(): void
    {
        // Arrange
        $items = [
            ['id' => 1, 'weight' => 10, 'count' => 2],
            ['id' => 2, 'weight' => 90, 'count' => 3],
        ];
        $pool = BoxPool::of(
            $items,
            fn ($i) => $i['weight'],
            fn ($i) => $i['count'],
            selectorClass: AliasTableSelector::class,
        );

        // Act
        $result = $pool->draw();

        // Assert
        $this->assertContains($result['id'], [1, 2], 'AliasTableSelector を使った draw がプール内のアイテムを返すこと');
    }

    // -------------------------------------------------------------------------
    // ヘルパー
    // -------------------------------------------------------------------------

    private function fixedRandomizer(int $value): RandomizerInterface
    {
        return new class ($value) implements RandomizerInterface {
            public function __construct(private readonly int $value)
            {
            }

            public function next(int $max): int
            {
                return min($this->value, $max - 1);
            }
        };
    }
}
