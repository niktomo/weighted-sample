<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Feature;

use PHPUnit\Framework\TestCase;
use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Pool\BoxPool;
use WeightedSample\Randomizer\SeededRandomizer;

class BoxPoolDrawTest extends TestCase
{
    public function test_total_draw_count_equals_sum_of_all_counts(): void
    {
        // Given: count の合計が 6 の BoxPool
        $items = [
            ['id' => 1, 'weight' => 10, 'count' => 1],
            ['id' => 2, 'weight' => 20, 'count' => 2],
            ['id' => 3, 'weight' => 70, 'count' => 3],
        ];
        $pool = BoxPool::of($items, fn ($i) => $i['weight'], fn ($i) => $i['count']);

        // When: 6回引く
        $drawn = [
            $pool->draw()['id'],
            $pool->draw()['id'],
            $pool->draw()['id'],
            $pool->draw()['id'],
            $pool->draw()['id'],
            $pool->draw()['id'],
        ];

        // Then: 合計6回引けてプールが空になること
        $this->assertCount(6, $drawn, 'count の合計と同じ回数だけ引けること');
        $this->assertTrue($pool->isEmpty(), '全 count を消費後にプールが空になること');
    }

    public function test_each_item_drawn_at_most_its_count_times(): void
    {
        // Given: id=1 は count=1、id=2 は count=2 の BoxPool（seed 固定）
        $items = [
            ['id' => 1, 'weight' => 50, 'count' => 1],
            ['id' => 2, 'weight' => 50, 'count' => 2],
        ];
        $pool = BoxPool::of($items, fn ($i) => $i['weight'], fn ($i) => $i['count'], randomizer: new SeededRandomizer(1));

        // When: 3回引く
        $drawn = [
            $pool->draw()['id'],
            $pool->draw()['id'],
            $pool->draw()['id'],
        ];

        // Then: id=1 は最大1回、id=2 は最大2回であること
        $counts = array_count_values($drawn);
        $this->assertLessThanOrEqual(1, $counts[1] ?? 0, 'id=1 は count=1 なので最大1回しか引かれないこと');
        $this->assertLessThanOrEqual(2, $counts[2] ?? 0, 'id=2 は count=2 なので最大2回しか引かれないこと');
    }

    public function test_draw_throws_after_all_counts_exhausted(): void
    {
        // Given: count 合計 = 2 の BoxPool
        $pool = BoxPool::of(
            [['id' => 1, 'weight' => 100, 'count' => 2]],
            fn ($i) => $i['weight'],
            fn ($i) => $i['count'],
        );

        // When: 2回引いてプールを枯渇させる
        $pool->draw();
        $pool->draw();

        // Then: 次の draw で EmptyPoolException がスローされること
        $this->expectException(EmptyPoolException::class);
        $pool->draw();
    }

    public function test_draw_sequence_is_deterministic_with_fixed_seed(): void
    {
        // Given: seed を固定した BoxPool
        $items = [
            ['id' => 1, 'weight' => 10, 'count' => 2],
            ['id' => 2, 'weight' => 20, 'count' => 2],
            ['id' => 3, 'weight' => 70, 'count' => 2],
        ];

        // When: 同じ seed の2つのプールから全6回引く
        $draw = function (int $seed) use ($items): array {
            $pool = BoxPool::of($items, fn ($i) => $i['weight'], fn ($i) => $i['count'], randomizer: new SeededRandomizer($seed));

            return [
                $pool->draw()['id'],
                $pool->draw()['id'],
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
