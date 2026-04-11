<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Feature;

use PHPUnit\Framework\TestCase;
use WeightedSample\Pool\BoxPool;
use WeightedSample\Randomizer\SeededRandomizer;

/**
 * DestructivePool は v2.0.0 で廃止。
 * count=1 固定の BoxPool で完全に代替できることをシナリオで示す。
 */
class DestructivePoolEquivalenceTest extends TestCase
{
    public function test_box_pool_with_count_one_draws_each_item_exactly_once(): void
    {
        // Given: count=1 固定の BoxPool（= DestructivePool 相当）
        $items = [
            ['id' => 1, 'weight' => 10],
            ['id' => 2, 'weight' => 5],
            ['id' => 3, 'weight' => 20],
            ['id' => 4, 'weight' => 65],
        ];
        $pool = BoxPool::of(
            $items,
            fn (array $item) => $item['weight'],
            fn (array $item) => 1,  // count=1 固定 → DestructivePool 相当
        );

        // When: 全4アイテムを引く
        $drawnIds = [
            $pool->draw()['id'],
            $pool->draw()['id'],
            $pool->draw()['id'],
            $pool->draw()['id'],
        ];

        // Then: 全アイテムが重複なく引かれ、プールが空になること
        sort($drawnIds);
        $this->assertSame([1, 2, 3, 4], $drawnIds, 'count=1 の BoxPool で各アイテムが1回ずつ引かれること');
        $this->assertTrue($pool->isEmpty(), '全アイテムを引き切るとプールが空になること');
    }

    public function test_box_pool_count_one_is_deterministic_with_same_seed(): void
    {
        // Given: seed 固定の count=1 BoxPool
        $items = [
            ['id' => 1, 'weight' => 10],
            ['id' => 2, 'weight' => 5],
            ['id' => 3, 'weight' => 20],
            ['id' => 4, 'weight' => 65],
        ];

        // When: 同じ seed の2つのプールから引く
        $draw = function (int $seed) use ($items): array {
            $pool = BoxPool::of(
                $items,
                fn (array $item) => $item['weight'],
                fn (array $item) => 1,
                randomizer: new SeededRandomizer($seed),
            );

            return [
                $pool->draw()['id'],
                $pool->draw()['id'],
                $pool->draw()['id'],
                $pool->draw()['id'],
            ];
        };

        // Then: 同じ seed なら同じ順序で引かれること
        $this->assertSame($draw(42), $draw(42), '同じ seed では常に同じ抽選順序になること');
    }
}
