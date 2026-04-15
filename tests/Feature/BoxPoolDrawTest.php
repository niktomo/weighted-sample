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
        $pool = BoxPool::of($items, static fn (array $item) => $item['weight'], static fn (array $item) => $item['count']);

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
        $pool = BoxPool::of($items, static fn (array $item) => $item['weight'], static fn (array $item) => $item['count'], randomizer: new SeededRandomizer(1));

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
            static fn (array $item) => $item['weight'],
            static fn (array $item) => $item['count'],
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
            $pool = BoxPool::of($items, static fn (array $item) => $item['weight'], static fn (array $item) => $item['count'], randomizer: new SeededRandomizer($seed));

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

    public function test_weight_distribution_shifts_when_item_exhausted(): void
    {
        // Given: Gold(w=50, stock=1) と Bronze(w=50, stock=9) の BoxPool
        // 初回は Gold:Bronze = 50:50 だが Gold を引き切ると Bronze のみ残る
        $items = [
            ['id' => 'Gold',   'weight' => 50, 'stock' => 1],
            ['id' => 'Bronze', 'weight' => 50, 'stock' => 9],
        ];
        $trials    = 10_000;
        $goldFirst = 0;

        // When: 10,000 試行して初回 draw が Gold である回数を数える
        // 各 trial に trial 番号をシードとして使い、再現性を確保する
        for ($trial = 0; $trial < $trials; $trial++) {
            $pool = BoxPool::of($items, static fn (array $item) => $item['weight'], static fn (array $item) => $item['stock'], randomizer: new SeededRandomizer($trial));
            if ($pool->draw()['id'] === 'Gold') {
                $goldFirst++;
            }
        }

        // Then: Gold の初回確率は 50% ± 1.5% の範囲に収まること
        // 3σ = 3 × sqrt(0.5 × 0.5 / 10000) = 3 × 0.005 = 0.015
        $goldRate = $goldFirst / $trials;
        $this->assertEqualsWithDelta(
            0.50,
            $goldRate,
            0.015,
            "Gold の初回抽選確率は約50%であること（実測: {$goldRate}）",
        );
    }

    public function test_remaining_items_reweighted_correctly_after_stock_exhaustion(): void
    {
        // Given: A(w=90, stock=1), B(w=5, stock=5), C(w=5, stock=5)
        // A が除外されると B:C = 5:5 = 50:50 になるはず
        $items = [
            ['id' => 'A', 'weight' => 90, 'stock' => 1],
            ['id' => 'B', 'weight' => 5,  'stock' => 5],
            ['id' => 'C', 'weight' => 5,  'stock' => 5],
        ];
        $trials  = 10_000;
        $bCount  = 0;
        $cCount  = 0;

        // When: A が出るまで引き、A 除外後の次の draw を記録する
        // 各 trial に trial 番号をシードとして使い、再現性を確保する
        for ($trial = 0; $trial < $trials; $trial++) {
            $pool = BoxPool::of($items, static fn (array $item) => $item['weight'], static fn (array $item) => $item['stock'], randomizer: new SeededRandomizer($trial));

            while (! $pool->isEmpty() && $pool->draw()['id'] !== 'A') {
                // A が出るまで消費
            }

            if (! $pool->isEmpty()) {
                $next = $pool->draw()['id'];
                if ($next === 'B') {
                    $bCount++;
                } elseif ($next === 'C') {
                    $cCount++;
                }
            }
        }

        // Then: A 除外後の B:C 比率は 50:50 ± 1.5% であること
        // 3σ = 3 × sqrt(0.5 × 0.5 / 10000) = 3 × 0.005 = 0.015
        $total = $bCount + $cCount;
        $this->assertGreaterThan(0, $total, 'A 除外後に B または C が引かれていること');
        $bRate = $bCount / $total;
        $this->assertEqualsWithDelta(
            0.50,
            $bRate,
            0.015,
            "A 除外後の B 出現率は約50%であること（実測: {$bRate}）",
        );
    }
}
