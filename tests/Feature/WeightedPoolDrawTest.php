<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Feature;

use PHPUnit\Framework\TestCase;
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Randomizer\SeededRandomizer;

class WeightedPoolDrawTest extends TestCase
{
    public function test_single_item_pool_always_returns_that_item(): void
    {
        // Given: アイテムが1つだけの WeightedPool
        $pool = WeightedPool::of(
            [['name' => 'only', 'weight' => 100]],
            static fn (array $item) => $item['weight'],
        );

        // When: 複数回 draw() する
        // Then: 常に唯一のアイテムが返ること
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame('only', $pool->draw()['name'], '単一アイテムのプールは常にそのアイテムを返すこと');
        }
    }

    public function test_draw_returns_deterministic_result_with_fixed_seed(): void
    {
        // Given: seed を固定した WeightedPool
        $items = [
            ['name' => 'SSR', 'weight' => 5],
            ['name' => 'R',   'weight' => 95],
        ];
        $pool = WeightedPool::of(
            $items,
            static fn (array $item) => $item['weight'],
            randomizer: new SeededRandomizer(42),
        );

        // When: draw() を3回行う
        $results = [$pool->draw()['name'], $pool->draw()['name'], $pool->draw()['name']];

        // Then: 同じ seed なら常に同じ列になること
        $pool2 = WeightedPool::of($items, static fn (array $item) => $item['weight'], randomizer: new SeededRandomizer(42));
        $this->assertSame(
            $results,
            [$pool2->draw()['name'], $pool2->draw()['name'], $pool2->draw()['name']],
            '同じ seed では常に同じ抽選列になること',
        );
    }

    public function test_pool_is_not_consumed_by_repeated_draws(): void
    {
        // Given: 2アイテムの WeightedPool（seed 固定）
        $items = [
            ['name' => 'A', 'weight' => 1],
            ['name' => 'B', 'weight' => 1],
        ];
        $pool = WeightedPool::of($items, static fn (array $item) => $item['weight']);

        // When: 50回 draw() する
        // Then: 例外が発生せずプールが枯渇しないこと
        for ($i = 0; $i < 50; $i++) {
            $item = $pool->draw();
            $this->assertContains($item['name'], ['A', 'B'], 'draw() がプール内のアイテムを返すこと');
        }
    }
}
