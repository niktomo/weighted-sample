<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Filter\StrictValueFilter;
use WeightedSample\Pool\DestructivePool;
use WeightedSample\Pool\ExhaustiblePoolInterface;
use WeightedSample\Randomizer\RandomizerInterface;

class DestructivePoolTest extends TestCase
{
    // -------------------------------------------------------------------------
    // 構築
    // -------------------------------------------------------------------------

    public function test_of_creates_instance(): void
    {
        // Arrange & Act
        $pool = DestructivePool::of(
            [['id' => 1, 'weight' => 10]],
            fn ($i) => $i['weight'],
        );

        // Assert
        $this->assertInstanceOf(DestructivePool::class, $pool, 'DestructivePool インスタンスが生成されること');
        $this->assertInstanceOf(ExhaustiblePoolInterface::class, $pool, 'DestructivePool が ExhaustiblePoolInterface を実装していること');
    }

    public function test_throws_on_empty_items(): void
    {
        // Assert
        $this->expectException(EmptyPoolException::class);

        // Act
        DestructivePool::of([], fn ($i) => $i['weight']);
    }

    public function test_zero_weight_items_are_excluded_by_default(): void
    {
        // Arrange — weight=0 のアイテムはデフォルトフィルターで除外される
        $items = [
            ['id' => 1, 'weight' => 0],
            ['id' => 2, 'weight' => 10],
        ];
        $pool = DestructivePool::of($items, fn ($i) => $i['weight'], randomizer: $this->fixedRandomizer(0));

        // Act & Assert — weight=0 の id=1 は除外され id=2 のみ残ること
        $this->assertSame(2, $pool->draw()['id'], 'weight=0 のアイテムが除外され残ったアイテムが返ること');
    }

    public function test_throws_when_all_items_are_filtered_out(): void
    {
        // Arrange — 全アイテムが weight=0
        $items = [
            ['id' => 1, 'weight' => 0],
            ['id' => 2, 'weight' => 0],
        ];

        // Assert
        $this->expectException(EmptyPoolException::class);

        // Act
        DestructivePool::of($items, fn ($i) => $i['weight']);
    }

    public function test_strict_filter_throws_on_zero_weight(): void
    {
        // Arrange
        $items = [['id' => 1, 'weight' => 0]];

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        DestructivePool::of($items, fn ($i) => $i['weight'], filter: new StrictValueFilter());
    }

    // -------------------------------------------------------------------------
    // draw() — アイテムが除外されること
    // -------------------------------------------------------------------------

    public function test_draw_returns_item(): void
    {
        // Arrange
        $items = [
            ['id' => 1, 'weight' => 10],
            ['id' => 2, 'weight' => 90],
        ];
        $pool = DestructivePool::of($items, fn ($i) => $i['weight']);

        // Act
        $item = $pool->draw();

        // Assert
        $this->assertContains($item['id'], [1, 2], 'draw() がプール内のアイテムを返すこと');
    }

    public function test_drawn_item_is_not_drawn_again(): void
    {
        // Arrange — rand=0 で必ず最初のアイテムを引く
        $items = [
            ['id' => 1, 'weight' => 10],
            ['id' => 2, 'weight' => 20],
            ['id' => 3, 'weight' => 70],
        ];
        $pool = DestructivePool::of($items, fn ($i) => $i['weight'], randomizer: $this->fixedRandomizer(0));

        // Act & Assert — 3回の draw でそれぞれ異なるアイテムが返ること
        $first  = $pool->draw()['id'];
        $second = $pool->draw()['id'];
        $third  = $pool->draw()['id'];

        $this->assertNotSame($first, $second, '1回目と2回目の draw は異なるアイテムを返すこと');
        $this->assertNotSame($second, $third, '2回目と3回目の draw は異なるアイテムを返すこと');
        $this->assertNotSame($first, $third, '1回目と3回目の draw は異なるアイテムを返すこと');
    }

    public function test_all_items_can_be_drawn_exactly_once(): void
    {
        // Arrange
        $items = [
            ['id' => 1, 'weight' => 10],
            ['id' => 2, 'weight' => 20],
            ['id' => 3, 'weight' => 30],
            ['id' => 4, 'weight' => 40],
        ];
        $pool = DestructivePool::of($items, fn ($i) => $i['weight']);

        // Act — 4アイテムを全て引く
        $drawnIds = [
            $pool->draw()['id'],
            $pool->draw()['id'],
            $pool->draw()['id'],
            $pool->draw()['id'],
        ];

        // Assert
        sort($drawnIds);
        $this->assertSame([1, 2, 3, 4], $drawnIds, '全4アイテムが重複なく過不足なく引かれること');
    }

    public function test_remaining_items_are_drawable_after_partial_depletion(): void
    {
        // Arrange — rand=0 で常に先頭を引く
        $items = [
            ['id' => 1, 'weight' => 10],
            ['id' => 2, 'weight' => 20],
            ['id' => 3, 'weight' => 70],
        ];
        $pool = DestructivePool::of($items, fn ($i) => $i['weight'], randomizer: $this->fixedRandomizer(0));

        // Act — 1つ引いて中断 → 残り2アイテムから draw
        $first  = $pool->draw()['id']; // id=1 が除外される
        $second = $pool->draw()['id']; // 残り [id=2, id=3] から rand=0 → id=2
        $third  = $pool->draw()['id']; // 残り [id=3] のみ → id=3

        // Assert
        $this->assertSame(1, $first, '1回目: rand=0 で先頭 id=1 が引かれること');
        $this->assertSame(2, $second, '2回目: id=1 除外後に rand=0 で id=2 が引かれること');
        $this->assertSame(3, $third, '3回目: id=2 除外後に残った id=3 が引かれること');
    }

    // -------------------------------------------------------------------------
    // isEmpty()
    // -------------------------------------------------------------------------

    public function test_is_not_empty_initially(): void
    {
        // Arrange
        $pool = DestructivePool::of([['id' => 1, 'weight' => 1]], fn ($i) => $i['weight']);

        // Assert
        $this->assertFalse($pool->isEmpty(), '構築直後は isEmpty() が false であること');
    }

    public function test_is_empty_after_all_items_drawn(): void
    {
        // Arrange
        $items = [
            ['id' => 1, 'weight' => 10],
            ['id' => 2, 'weight' => 90],
        ];
        $pool = DestructivePool::of($items, fn ($i) => $i['weight']);

        // Act
        $pool->draw();
        $pool->draw();

        // Assert
        $this->assertTrue($pool->isEmpty(), '全アイテムを引き切った後 isEmpty() が true になること');
    }

    public function test_draw_throws_on_empty_pool(): void
    {
        // Arrange
        $pool = DestructivePool::of([['id' => 1, 'weight' => 1]], fn ($i) => $i['weight']);
        $pool->draw();

        // Assert
        $this->expectException(EmptyPoolException::class);

        // Act
        $pool->draw();
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
