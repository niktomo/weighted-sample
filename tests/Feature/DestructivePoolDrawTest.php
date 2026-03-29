<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Feature;

use PHPUnit\Framework\TestCase;
use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Pool\DestructivePool;
use WeightedSample\Randomizer\SeededRandomizer;

class DestructivePoolDrawTest extends TestCase
{
    public function test_each_item_is_drawn_exactly_once(): void
    {
        // Given: id と weight を持つ4アイテムの DestructivePool
        $items = [
            ['id' => 1, 'weight' => 10],
            ['id' => 2, 'weight' => 5],
            ['id' => 3, 'weight' => 20],
            ['id' => 4, 'weight' => 65],
        ];
        $pool = DestructivePool::of($items, fn ($i) => $i['weight']);

        // When: 全4アイテムを引く
        $drawnIds = [
            $pool->draw()['id'],
            $pool->draw()['id'],
            $pool->draw()['id'],
            $pool->draw()['id'],
        ];

        // Then: 全4アイテムが重複なく過不足なく引かれること
        sort($drawnIds);
        $this->assertSame([1, 2, 3, 4], $drawnIds, '全アイテムが重複なく引かれること');
    }

    public function test_draw_throws_after_pool_exhausted(): void
    {
        // Given: 1アイテムの DestructivePool
        $pool = DestructivePool::of(
            [['id' => 1, 'weight' => 100]],
            fn ($i) => $i['weight'],
        );

        // When: 唯一のアイテムを引く
        $pool->draw();

        // Then: 次の draw で EmptyPoolException がスローされること
        $this->expectException(EmptyPoolException::class);
        $pool->draw();
    }

    public function test_single_item_pool_always_draws_that_item(): void
    {
        // Given: 1アイテムのみの DestructivePool（重みに関係なく必ずそのアイテム）
        $pool = DestructivePool::of(
            [['id' => 99, 'weight' => 1]],
            fn ($i) => $i['weight'],
        );

        // When & Then: draw() は必ず id=99 を返すこと
        $item = $pool->draw();
        $this->assertSame(99, $item['id'], '単一アイテムのプールは必ずそのアイテムを返すこと');
    }

    public function test_draw_sequence_is_deterministic_with_fixed_seed(): void
    {
        // Given: seed を固定した DestructivePool
        $items = [
            ['id' => 1, 'weight' => 10],
            ['id' => 2, 'weight' => 5],
            ['id' => 3, 'weight' => 20],
            ['id' => 4, 'weight' => 65],
        ];

        // When: 2つの同一 seed プールから全4アイテムを引く
        $draw = function (int $seed) use ($items): array {
            $pool = DestructivePool::of($items, fn ($i) => $i['weight'], randomizer: new SeededRandomizer($seed));

            return [
                $pool->draw()['id'],
                $pool->draw()['id'],
                $pool->draw()['id'],
                $pool->draw()['id'],
            ];
        };

        // Then: 同じ seed なら常に同じ順序で引かれること
        $this->assertSame($draw(42), $draw(42), '同じ seed では常に同じ抽選順序になること');
    }
}
