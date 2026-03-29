<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Filter\StrictValueFilter;
use WeightedSample\Pool\PoolInterface;
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Randomizer\RandomizerInterface;

class WeightedPoolTest extends TestCase
{
    // -------------------------------------------------------------------------
    // 構築
    // -------------------------------------------------------------------------

    public function test_of_creates_instance(): void
    {
        // Arrange & Act
        $pool = WeightedPool::of(
            [['name' => 'A', 'weight' => 10]],
            fn ($i) => $i['weight'],
        );

        // Assert
        $this->assertInstanceOf(WeightedPool::class, $pool, 'WeightedPool インスタンスが生成されること');
        $this->assertInstanceOf(PoolInterface::class, $pool, 'WeightedPool が PoolInterface を実装していること');
    }

    public function test_throws_on_empty_items(): void
    {
        // Assert
        $this->expectException(EmptyPoolException::class);

        // Act
        WeightedPool::of([], fn ($i) => $i['weight']);
    }

    public function test_zero_weight_items_are_excluded_by_default(): void
    {
        // Arrange — weight=0 のアイテムはデフォルトフィルターで除外される
        $items = [
            ['name' => 'A', 'weight' => 0],
            ['name' => 'B', 'weight' => 10],
        ];
        $pool = WeightedPool::of($items, fn ($i) => $i['weight'], randomizer: $this->fixedRandomizer(0));

        // Act & Assert — weight=0 の A は除外され B のみ残ること
        $this->assertSame('B', $pool->draw()['name'], 'weight=0 のアイテムが除外され残ったアイテムが返ること');
    }

    public function test_throws_when_all_items_are_filtered_out(): void
    {
        // Arrange — 全アイテムが weight=0
        $items = [
            ['name' => 'A', 'weight' => 0],
            ['name' => 'B', 'weight' => 0],
        ];

        // Assert
        $this->expectException(EmptyPoolException::class);

        // Act
        WeightedPool::of($items, fn ($i) => $i['weight']);
    }

    public function test_strict_filter_throws_on_zero_weight(): void
    {
        // Arrange
        $items = [['name' => 'A', 'weight' => 0]];

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        WeightedPool::of($items, fn ($i) => $i['weight'], filter: new StrictValueFilter());
    }

    // -------------------------------------------------------------------------
    // draw() — 決定論的なスタブで検証
    // -------------------------------------------------------------------------

    public function test_draw_returns_first_item_when_rand_is_zero(): void
    {
        // Arrange
        $items = [
            ['name' => 'A', 'weight' => 10],
            ['name' => 'B', 'weight' => 90],
        ];
        $pool = WeightedPool::of(
            $items,
            fn ($i) => $i['weight'],
            randomizer: $this->fixedRandomizer(0), // rand=0 → 最初のアイテム
        );

        // Act
        $result = $pool->draw();

        // Assert
        $this->assertSame('A', $result['name'], 'rand=0 のとき最初のアイテムが返ること');
    }

    public function test_draw_returns_last_item_when_rand_is_total_minus_one(): void
    {
        // Arrange — total=100, rand=99 → 最後のアイテム
        $items = [
            ['name' => 'A', 'weight' => 10],
            ['name' => 'B', 'weight' => 90],
        ];
        $pool = WeightedPool::of(
            $items,
            fn ($i) => $i['weight'],
            randomizer: $this->fixedRandomizer(99),
        );

        // Act
        $result = $pool->draw();

        // Assert
        $this->assertSame('B', $result['name'], 'rand=total-1 のとき最後のアイテムが返ること');
    }

    public function test_draw_does_not_modify_pool(): void
    {
        // Arrange
        $items = [
            ['name' => 'A', 'weight' => 50],
            ['name' => 'B', 'weight' => 50],
        ];
        $pool = WeightedPool::of($items, fn ($i) => $i['weight']);

        // Act — 複数回 draw しても同じ pool から引ける
        $first  = $pool->draw();
        $second = $pool->draw();

        // Assert
        $this->assertContains($first['name'], ['A', 'B'], '1回目の draw がプール内のアイテムであること');
        $this->assertContains($second['name'], ['A', 'B'], '2回目の draw がプール内のアイテムであること');
    }

    // -------------------------------------------------------------------------
    // draw() — オブジェクトアイテム
    // -------------------------------------------------------------------------

    public function test_draw_works_with_object_items(): void
    {
        // Arrange
        $a = new \stdClass();
        $a->name   = 'A';
        $a->weight = 10;

        $b = new \stdClass();
        $b->name   = 'B';
        $b->weight = 90;

        $pool = WeightedPool::of(
            [$a, $b],
            fn ($obj) => $obj->weight,
            randomizer: $this->fixedRandomizer(0),
        );

        // Act
        $result = $pool->draw();

        // Assert
        $this->assertSame('A', $result->name, 'オブジェクトアイテムでも rand=0 で最初のアイテムが返ること');
    }

    // -------------------------------------------------------------------------
    // withRandomizer()
    // -------------------------------------------------------------------------

    public function test_with_randomizer_returns_new_instance(): void
    {
        // Arrange
        $pool    = WeightedPool::of([['name' => 'A', 'weight' => 1]], fn ($i) => $i['weight']);
        $newPool = $pool->withRandomizer($this->fixedRandomizer(0));

        // Assert
        $this->assertNotSame($pool, $newPool, 'withRandomizer() は新しいインスタンスを返すこと');
    }

    public function test_with_randomizer_new_instance_uses_new_randomizer(): void
    {
        // Arrange — [A weight=10, B weight=90] で rand=0 → A、rand=99 → B
        $items = [
            ['name' => 'A', 'weight' => 10],
            ['name' => 'B', 'weight' => 90],
        ];
        $pool    = WeightedPool::of($items, fn ($i) => $i['weight'], randomizer: $this->fixedRandomizer(0));
        $newPool = $pool->withRandomizer($this->fixedRandomizer(99));

        // Act
        $fromOriginal = $pool->draw()['name'];
        $fromNew      = $newPool->draw()['name'];

        // Assert
        $this->assertSame('A', $fromOriginal, '元のプールは rand=0 なので A を返すこと');
        $this->assertSame('B', $fromNew, 'withRandomizer() 後のプールは rand=99 なので B を返すこと');
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
                return $this->value;
            }
        };
    }
}
