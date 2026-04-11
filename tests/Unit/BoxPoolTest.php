<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WeightedSample\Builder\FenwickSelectorBundleFactory;
use WeightedSample\Builder\RebuildSelectorBundleFactory;
use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Filter\StrictValueFilter;
use WeightedSample\Pool\BoxPool;
use WeightedSample\Pool\ExhaustiblePoolInterface;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\SelectorBundleFactoryInterface;

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
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['count'],
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
        BoxPool::of([], fn (array $item) => $item['weight'], fn (array $item) => $item['count']);
    }

    public function test_zero_count_items_are_excluded_by_default(): void
    {
        // Arrange — count=0 のアイテムはデフォルトフィルターで除外される
        $items = [
            ['id' => 1, 'weight' => 10, 'count' => 0],
            ['id' => 2, 'weight' => 90, 'count' => 1],
        ];
        $pool = BoxPool::of($items, fn (array $item) => $item['weight'], fn (array $item) => $item['count'], randomizer: $this->fixedRandomizer(0));

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
        BoxPool::of($items, fn (array $item) => $item['weight'], fn (array $item) => $item['count']);
    }

    public function test_strict_filter_throws_on_zero_count(): void
    {
        // Arrange
        $items = [['id' => 1, 'weight' => 10, 'count' => 0]];

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        BoxPool::of($items, fn (array $item) => $item['weight'], fn (array $item) => $item['count'], filter: new StrictValueFilter());
    }

    // -------------------------------------------------------------------------
    // 元データの不変性
    // -------------------------------------------------------------------------

    public function test_original_items_array_is_not_mutated_by_draw(): void
    {
        // Arrange — 外部から渡す配列のスナップショットを保持
        $items    = [
            ['id' => 1, 'weight' => 10, 'count' => 2],
            ['id' => 2, 'weight' => 90, 'count' => 3],
        ];
        $snapshot = $items;
        $pool     = BoxPool::of($items, fn (array $item) => $item['weight'], fn (array $item) => $item['count']);

        // Act — draw して内部状態（count）を変化させる
        $pool->draw();
        $pool->draw();

        // Assert — 外部から渡した配列は変更されないこと
        $this->assertSame($snapshot, $items, 'BoxPool::of() に渡した元配列は draw() 後も変更されないこと');
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
        $pool = BoxPool::of($items, fn (array $item) => $item['weight'], fn (array $item) => $item['count']);

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
        $pool = BoxPool::of($items, fn (array $item) => $item['weight'], fn (array $item) => $item['count'], randomizer: $this->fixedRandomizer(0));

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
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['count'],
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
        $pool = BoxPool::of($items, fn (array $item) => $item['weight'], fn (array $item) => $item['count'], randomizer: $this->fixedRandomizer(0));

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
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['count'],
        );
        $pool->draw();

        // Assert
        $this->expectException(EmptyPoolException::class);

        // Act
        $pool->draw();
    }

    // -------------------------------------------------------------------------
    // selectorBundleFactory — ファクトリ差し替え
    // -------------------------------------------------------------------------

    public function test_accepts_selector_bundle_factory_interface(): void
    {
        // Arrange — SelectorBundleFactoryInterface 型として注入できること
        $factory = new FenwickSelectorBundleFactory();
        $this->assertInstanceOf(SelectorBundleFactoryInterface::class, $factory, 'FenwickSelectorBundleFactory が SelectorBundleFactoryInterface を実装していること');

        $pool = BoxPool::of(
            [['id' => 1, 'weight' => 10, 'count' => 2]],
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['count'],
            selectorBundleFactory: $factory,
        );

        // Assert
        $this->assertInstanceOf(BoxPool::class, $pool, 'SelectorBundleFactoryInterface 経由で BoxPool が構築されること');
    }

    // -------------------------------------------------------------------------
    // FenwickSelectorBundleFactory — O(log n) update パス
    // -------------------------------------------------------------------------

    public function test_fenwick_bundle_item_excluded_after_count_exhausted(): void
    {
        // Arrange — FenwickSelectorBuilder path: count=0 triggers subtract(index) in O(log n)
        $items = [
            ['id' => 1, 'weight' => 10, 'count' => 1],
            ['id' => 2, 'weight' => 90, 'count' => 3],
        ];
        $pool = BoxPool::of(
            $items,
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['count'],
            selectorBundleFactory: new FenwickSelectorBundleFactory(),
            randomizer: $this->fixedRandomizer(0),
        );

        // Act
        $first  = $pool->draw()['id']; // id=1, count=0 → excluded via subtract(0)
        $second = $pool->draw()['id']; // only id=2 remains
        $third  = $pool->draw()['id'];
        $fourth = $pool->draw()['id'];

        // Assert
        $this->assertSame(1, $first, 'FenwickBundle: 1回目: count=1 のアイテムが引かれること');
        $this->assertSame(2, $second, 'FenwickBundle: 2回目: count=0 になったアイテムは除外され id=2 が引かれること');
        $this->assertSame(2, $third, 'FenwickBundle: 3回目: id=2 が引かれること');
        $this->assertSame(2, $fourth, 'FenwickBundle: 4回目: id=2 が引かれること');
    }

    public function test_fenwick_bundle_is_empty_after_all_counts_exhausted(): void
    {
        // Arrange
        $items = [
            ['id' => 1, 'weight' => 10, 'count' => 2],
            ['id' => 2, 'weight' => 90, 'count' => 1],
        ];
        $pool = BoxPool::of(
            $items,
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['count'],
            selectorBundleFactory: new FenwickSelectorBundleFactory(),
            randomizer: $this->fixedRandomizer(0),
        );

        // Act
        $pool->draw(); // id=1, count=1
        $pool->draw(); // id=1, count=0 → excluded
        $pool->draw(); // id=2, count=0 → excluded

        // Assert
        $this->assertTrue($pool->isEmpty(), 'FenwickBundle: 全 count を消費した後 isEmpty() が true になること');
    }

    public function test_fenwick_bundle_throws_on_empty_pool(): void
    {
        // Arrange
        $pool = BoxPool::of(
            [['id' => 1, 'weight' => 10, 'count' => 1]],
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['count'],
            selectorBundleFactory: new FenwickSelectorBundleFactory(),
        );
        $pool->draw();

        // Assert
        $this->expectException(EmptyPoolException::class);

        // Act
        $pool->draw();
    }

    // -------------------------------------------------------------------------
    // RebuildSelectorBundleFactory — O(n) rebuild パス
    // -------------------------------------------------------------------------

    public function test_rebuild_bundle_item_excluded_after_count_exhausted(): void
    {
        // Arrange — RebuildSelectorBuilder path: count=0 triggers subtract(index) then rebuild
        $items = [
            ['id' => 1, 'weight' => 10, 'count' => 1],
            ['id' => 2, 'weight' => 90, 'count' => 3],
        ];
        $pool = BoxPool::of(
            $items,
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['count'],
            selectorBundleFactory: new RebuildSelectorBundleFactory(),
            randomizer: $this->fixedRandomizer(0),
        );

        // Act
        $first  = $pool->draw()['id'];
        $second = $pool->draw()['id'];
        $third  = $pool->draw()['id'];
        $fourth = $pool->draw()['id'];

        // Assert
        $this->assertSame(1, $first, 'RebuildBundle: 1回目: count=1 のアイテムが引かれること');
        $this->assertSame(2, $second, 'RebuildBundle: 2回目: count=0 になったアイテムは除外され id=2 が引かれること');
        $this->assertSame(2, $third, 'RebuildBundle: 3回目: id=2 が引かれること');
        $this->assertSame(2, $fourth, 'RebuildBundle: 4回目: id=2 が引かれること');
    }

    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // iterable サポート
    // -------------------------------------------------------------------------

    public function test_of_accepts_generator_as_items(): void
    {
        // Arrange — ジェネレータでアイテムをストリーミング供給する
        $generator = (function () {
            yield ['id' => 1, 'weight' => 10, 'count' => 2];
            yield ['id' => 2, 'weight' => 90, 'count' => 3];
        })();

        // Act
        $pool = BoxPool::of($generator, fn (array $item) => $item['weight'], fn (array $item) => $item['count']);

        // Assert — ジェネレータ入力でも正常にプールが構築されること
        $this->assertInstanceOf(BoxPool::class, $pool, 'Generator を渡してもプールが生成されること');
        $result = $pool->draw();
        $this->assertContains($result['id'], [1, 2], 'draw() がプール内のアイテムを返すこと');
    }

    // -------------------------------------------------------------------------
    // drawMany()
    // -------------------------------------------------------------------------

    public function test_draw_many_returns_empty_when_count_is_zero(): void
    {
        // Arrange
        $pool = BoxPool::of(
            [['id' => 1, 'weight' => 10, 'count' => 5]],
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['count'],
        );

        // Act
        $result = $pool->drawMany(0);

        // Assert
        $this->assertSame([], $result, 'count=0 のとき drawMany() は空 list を返すこと');
    }

    public function test_draw_many_returns_empty_when_pool_is_already_empty(): void
    {
        // Arrange — count=1 のアイテムを draw() で使い切る
        $pool = BoxPool::of(
            [['id' => 1, 'weight' => 10, 'count' => 1]],
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['count'],
        );
        $pool->draw();

        // Act
        $result = $pool->drawMany(3);

        // Assert
        $this->assertSame([], $result, 'pool が空のとき drawMany() は空 list を返すこと');
    }

    public function test_draw_many_returns_requested_count_when_pool_has_enough(): void
    {
        // Arrange — count=10 のアイテムが1種類
        $pool = BoxPool::of(
            [['id' => 1, 'weight' => 10, 'count' => 10]],
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['count'],
            randomizer: $this->fixedRandomizer(0),
        );

        // Act
        $result = $pool->drawMany(3);

        // Assert
        $this->assertCount(3, $result, 'pool に十分な在庫があるとき drawMany() は count 個返すこと');
        $this->assertSame(1, $result[0]['id'], '1件目が正しいアイテムであること');
        $this->assertSame(1, $result[1]['id'], '2件目が正しいアイテムであること');
        $this->assertSame(1, $result[2]['id'], '3件目が正しいアイテムであること');
    }

    public function test_draw_many_stops_early_when_pool_exhausted(): void
    {
        // Arrange — count合計=3 なので 5回要求しても 3件で止まること
        $items = [
            ['id' => 1, 'weight' => 10, 'count' => 1],
            ['id' => 2, 'weight' => 10, 'count' => 2],
        ];
        $pool = BoxPool::of(
            $items,
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['count'],
            randomizer: $this->fixedRandomizer(0),
        );

        // Act
        $result = $pool->drawMany(5);

        // Assert
        $this->assertCount(3, $result, 'pool が途中で空になったとき drawMany() は引けた分だけ返すこと');
        $this->assertTrue($pool->isEmpty(), 'drawMany() 後に pool が空になること');
    }

    public function test_draw_many_throws_on_negative_count(): void
    {
        // Arrange
        $pool = BoxPool::of(
            [['id' => 1, 'weight' => 10, 'count' => 5]],
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['count'],
        );

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        $pool->drawMany(-1);
    }

    public function test_draw_many_throws_on_php_int_min(): void
    {
        // Arrange — PHP_INT_MIN は最も小さい負の整数
        $pool = BoxPool::of(
            [['id' => 1, 'weight' => 10, 'count' => 5]],
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['count'],
        );

        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        $pool->drawMany(\PHP_INT_MIN);
    }

    public function test_draw_many_with_php_int_max_count_returns_all_items(): void
    {
        // Arrange — PHP_INT_MAX を指定しても pool の在庫分だけ返すこと
        $pool = BoxPool::of(
            [['id' => 1, 'weight' => 10, 'count' => 3]],
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['count'],
            randomizer: $this->fixedRandomizer(0),
        );

        // Act
        $result = $pool->drawMany(\PHP_INT_MAX);

        // Assert
        $this->assertCount(3, $result, 'PHP_INT_MAX を指定しても在庫数分（3件）だけ返すこと');
        $this->assertTrue($pool->isEmpty(), 'drawMany 後に pool が空になること');
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
