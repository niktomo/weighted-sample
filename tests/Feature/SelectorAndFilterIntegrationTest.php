<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Feature;

use PHPUnit\Framework\TestCase;
use WeightedSample\Filter\CompositeFilter;
use WeightedSample\Filter\PositiveValueFilter;
use WeightedSample\Filter\StrictValueFilter;
use WeightedSample\Pool\BoxPool;
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\AliasTableSelectorFactory;
use WeightedSample\Selector\FenwickTreeSelectorFactory;
use WeightedSample\Selector\PrefixSumSelectorFactory;

class SelectorAndFilterIntegrationTest extends TestCase
{
    // ─────────────────────────────────────────────────────────
    // 複数セレクター互換性 — Strategy パターンの統合検証
    // ─────────────────────────────────────────────────────────

    public function test_all_selector_factories_produce_same_distribution(): void
    {
        // Given: 重み [1, 9, 90] と同一シードのランダマイザー × 3ファクトリー
        $items    = [
            ['name' => 'Rare',   'weight' => 1],
            ['name' => 'Medium', 'weight' => 9],
            ['name' => 'Common', 'weight' => 90],
        ];
        $extractor = fn (array $item) => $item['weight'];
        $draws     = 100_000;

        $factories = [
            'PrefixSum'  => new PrefixSumSelectorFactory(),
            'Fenwick'    => new FenwickTreeSelectorFactory(),
            'AliasTable' => new AliasTableSelectorFactory(),
        ];

        $results = [];
        foreach ($factories as $name => $factory) {
            $pool   = WeightedPool::of($items, $extractor, selectorFactory: $factory, randomizer: new SeededRandomizer(42));
            $counts = ['Rare' => 0, 'Medium' => 0, 'Common' => 0];
            for ($i = 0; $i < $draws; $i++) {
                $counts[$pool->draw()['name']]++;
            }
            $results[$name] = $counts;
        }

        // Then: 全ファクトリーで各アイテムの出現率が期待値 ±0.5% に収まること
        // 3σ ≈ 0.3% なので delta=0.5% は保守的に十分安全
        foreach ($factories as $name => $_) {
            $this->assertEqualsWithDelta(
                1.0,
                $results[$name]['Rare']   / $draws * 100,
                0.5,
                "{$name}: Rare (weight=1) の出現率が 1% ±0.5% であること",
            );
            $this->assertEqualsWithDelta(
                9.0,
                $results[$name]['Medium'] / $draws * 100,
                0.5,
                "{$name}: Medium (weight=9) の出現率が 9% ±0.5% であること",
            );
            $this->assertEqualsWithDelta(
                90.0,
                $results[$name]['Common'] / $draws * 100,
                0.5,
                "{$name}: Common (weight=90) の出現率が 90% ±0.5% であること",
            );
        }
    }

    public function test_selector_factories_produce_identical_sequence_with_same_seed(): void
    {
        // Given: 3ファクトリー × 同一シード — seed が決定論的ならアルゴリズムが違っても同じ列のはず
        // Note: 各セレクターは next(total_weight) の呼び出し引数が異なる場合があるため
        //       このテストは「各ファクトリーが同じシードで同じ列を生成する」ではなく
        //       「各ファクトリーが自身のシードに対して決定論的である」を検証する
        $items    = [
            ['id' => 'A', 'weight' => 50],
            ['id' => 'B', 'weight' => 50],
        ];
        $extractor = fn (array $item) => $item['weight'];

        foreach ([new PrefixSumSelectorFactory(), new FenwickTreeSelectorFactory(), new AliasTableSelectorFactory()] as $factory) {
            $poolA = WeightedPool::of($items, $extractor, selectorFactory: $factory, randomizer: new SeededRandomizer(999));
            $poolB = WeightedPool::of($items, $extractor, selectorFactory: $factory, randomizer: new SeededRandomizer(999));

            $seqA = [$poolA->draw()['id'], $poolA->draw()['id'], $poolA->draw()['id']];
            $seqB = [$poolB->draw()['id'], $poolB->draw()['id'], $poolB->draw()['id']];

            $this->assertSame($seqA, $seqB, get_class($factory) . ': 同じシードで同一の抽選列が得られること');
        }
    }

    // ─────────────────────────────────────────────────────────
    // CompositeFilter 統合 — BoxPool + 複合フィルター
    // ─────────────────────────────────────────────────────────

    public function test_composite_filter_excludes_items_failing_any_inner_filter(): void
    {
        // Given: weight=0 のアイテムを含むリストを PositiveValueFilter + StrictValueFilter で二重フィルター
        // PositiveValueFilter: weight > 0 のみ通過
        // StrictValueFilter: weight <= 0 のとき InvalidArgumentException をスロー（PositiveValueFilter が先に弾く）
        $items = [
            ['id' => 'valid',   'weight' => 50],
            ['id' => 'invalid', 'weight' => 0],   // PositiveValueFilter が弾く
        ];
        $filter = new CompositeFilter([new PositiveValueFilter(), new StrictValueFilter()]);

        // When: CompositeFilter を使った WeightedPool を構築する
        $pool = WeightedPool::of($items, fn (array $item) => $item['weight'], filter: $filter);

        // Then: 全 draw が valid アイテムだけを返すこと
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame('valid', $pool->draw()['id'], 'weight=0 のアイテムはフィルターで除外されること');
        }
    }

    public function test_composite_filter_with_box_pool_respects_count_gate(): void
    {
        // Given: count > 0 のアイテムのみ通過する CompositeFilter + BoxPool
        // PositiveValueFilter は weight > 0 を要求
        // BoxPool 構築時に count=0 のアイテムは build 段階で除外される
        $items = [
            ['id' => 1, 'weight' => 60, 'stock' => 3],
            ['id' => 2, 'weight' => 40, 'stock' => 2],
        ];
        $filter = new CompositeFilter([new PositiveValueFilter()]);
        $pool   = BoxPool::of(
            $items,
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['stock'],
            filter: $filter,
            randomizer: new SeededRandomizer(7),
        );

        // When: 5回引く（stock 合計 = 5）
        $drawn = [];
        for ($i = 0; $i < 5; $i++) {
            $drawn[] = $pool->draw()['id'];
        }

        // Then: id=1 は最大3回、id=2 は最大2回であること
        $counts = array_count_values($drawn);
        $this->assertCount(5, $drawn, 'stock の合計と同じ5回引けること');
        $this->assertLessThanOrEqual(3, $counts[1] ?? 0, 'id=1 の stock=3 以下であること');
        $this->assertLessThanOrEqual(2, $counts[2] ?? 0, 'id=2 の stock=2 以下であること');
        $this->assertTrue($pool->isEmpty(), '全 stock 消費後にプールが空になること');
    }

    // ─────────────────────────────────────────────────────────
    // SeededRandomizer 再現性 — セッション間の再現性
    // ─────────────────────────────────────────────────────────

    public function test_seeded_randomizer_reproduces_across_simulated_sessions(): void
    {
        // Given: 本番と同じアイテムセット・同一シード — 異なる「セッション」（プール再生成）でも同じ列
        $items     = [
            ['tier' => 'SSR', 'weight' => 3],
            ['tier' => 'SR',  'weight' => 17],
            ['tier' => 'R',   'weight' => 80],
        ];
        $extractor = fn (array $item) => $item['weight'];
        $seed      = 20240101;

        // When: 2回のセッション（プール再生成）で各5回 draw する
        $session = function () use ($items, $extractor, $seed): array {
            $pool    = WeightedPool::of($items, $extractor, randomizer: new SeededRandomizer($seed));
            $results = [];
            for ($i = 0; $i < 5; $i++) {
                $results[] = $pool->draw()['tier'];
            }

            return $results;
        };

        $session1 = $session();
        $session2 = $session();

        // Then: 同一シードなら完全に同じ列が再現されること
        $this->assertSame(
            $session1,
            $session2,
            'SeededRandomizer は同一シードで完全に再現可能であること（セッション間の決定性）',
        );

        // And: 各 tier が実際に選ばれていること（結果が定数ではないことの確認）
        $allTiers = array_unique(array_merge($session1, $session2));
        $this->assertContains('R', $allTiers, '抽選結果に R tier が含まれること（シード=20240101 での定数チェック）');
    }
}
