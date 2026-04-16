<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use WeightedSample\Filter\StrictValueFilter;
use WeightedSample\Pool\PoolInterface;
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Selector\AliasTableSelectorFactory;
use WeightedSample\SelectorFactoryInterface;
use WeightedSample\Tests\Support\RandomizerHelpers;

class WeightedPoolTest extends TestCase
{
    use RandomizerHelpers;

    // -------------------------------------------------------------------------
    // 構築
    // -------------------------------------------------------------------------

    public function test_of_creates_instance(): void
    {
        // Arrange & Act
        $pool = WeightedPool::of(
            [['name' => 'A', 'weight' => 10]],
            static fn (array $item): int => $item['weight'],
        );

        // Assert
        $this->assertInstanceOf(WeightedPool::class, $pool, 'WeightedPool インスタンスが生成されること');
        $this->assertInstanceOf(PoolInterface::class, $pool, 'WeightedPool が PoolInterface を実装していること');
    }

    public function test_throws_on_empty_items(): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        WeightedPool::of([], static fn (array $item): int => $item['weight']);
    }

    public function test_zero_weight_items_are_excluded_by_default(): void
    {
        // Arrange — weight=0 のアイテムはデフォルトフィルターで除外される
        $items = [
            ['name' => 'A', 'weight' => 0],
            ['name' => 'B', 'weight' => 10],
        ];
        $pool = WeightedPool::of($items, static fn (array $item): int => $item['weight'], randomizer: $this->fixedRandomizer(0));

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

        // Assert — 構築時の InvalidArgumentException は実行時の EmptyPoolException と区別されること
        $this->expectException(InvalidArgumentException::class);

        // Act
        WeightedPool::of($items, static fn (array $item): int => $item['weight']);
    }

    public function test_strict_filter_throws_on_zero_weight(): void
    {
        // Arrange
        $items = [['name' => 'A', 'weight' => 0]];

        // Assert
        $this->expectException(InvalidArgumentException::class);

        // Act
        WeightedPool::of($items, static fn (array $item): int => $item['weight'], filter: new StrictValueFilter());
    }

    // -------------------------------------------------------------------------
    // draw() — 決定論的なスタブで検証
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{int, string}>
     */
    public static function drawRandAndExpectedItemProvider(): array
    {
        return [
            'rand=0 → 最初のアイテム (A)'   => [0,  'A'],
            'rand=99 → 最後のアイテム (B)'  => [99, 'B'],
        ];
    }

    #[DataProvider('drawRandAndExpectedItemProvider')]
    public function test_draw_returns_item_based_on_rand_value(int $randValue, string $expectedName): void
    {
        // Arrange
        $items = [
            ['name' => 'A', 'weight' => 10],
            ['name' => 'B', 'weight' => 90],
        ];
        $pool = WeightedPool::of(
            $items,
            static fn (array $item): int => $item['weight'],
            randomizer: $this->fixedRandomizer($randValue),
        );

        // Act
        $result = $pool->draw();

        // Assert
        $this->assertSame($expectedName, $result['name'], "rand={$randValue} のとき {$expectedName} が返ること");
    }

    public function test_draw_does_not_modify_pool(): void
    {
        // Arrange
        $items = [
            ['name' => 'A', 'weight' => 50],
            ['name' => 'B', 'weight' => 50],
        ];
        $pool = WeightedPool::of($items, static fn (array $item): int => $item['weight']);

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
            static fn (stdClass $obj): int => $obj->weight,
            randomizer: $this->fixedRandomizer(0),
        );

        // Act
        $result = $pool->draw();

        // Assert
        $this->assertSame('A', $result->name, 'オブジェクトアイテムでも rand=0 で最初のアイテムが返ること');
    }

    // -------------------------------------------------------------------------
    // selectorFactory — セレクターファクトリ差し替え
    // -------------------------------------------------------------------------

    public function test_accepts_selector_factory_interface(): void
    {
        // Arrange
        $items = [
            ['name' => 'A', 'weight' => 10],
            ['name' => 'B', 'weight' => 90],
        ];
        $pool = WeightedPool::of(
            $items,
            static fn (array $item): int => $item['weight'],
            selectorFactory: new AliasTableSelectorFactory(),
        );

        // Act
        $result = $pool->draw();

        // Assert
        $this->assertContains($result['name'], ['A', 'B'], 'SelectorFactoryInterface を注入した draw がプール内のアイテムを返すこと');
    }

    public function test_selector_factory_parameter_accepts_interface_type(): void
    {
        // Arrange — SelectorFactoryInterface 型として注入できること
        $factory = new AliasTableSelectorFactory();
        $this->assertInstanceOf(SelectorFactoryInterface::class, $factory, 'AliasTableSelectorFactory が SelectorFactoryInterface を実装していること');

        $pool = WeightedPool::of(
            [['name' => 'A', 'weight' => 10]],
            static fn (array $item): int => $item['weight'],
            selectorFactory: $factory,
        );

        // Assert
        $this->assertInstanceOf(WeightedPool::class, $pool, 'SelectorFactoryInterface 経由で WeightedPool が構築されること');
    }

    // -------------------------------------------------------------------------
    // iterable サポート
    // -------------------------------------------------------------------------

    public function test_of_accepts_generator_as_items(): void
    {
        // Arrange — ジェネレータでアイテムをストリーミング供給する
        $generator = (function () {
            yield ['name' => 'A', 'weight' => 10];
            yield ['name' => 'B', 'weight' => 90];
        })();

        // Act
        $pool = WeightedPool::of($generator, static fn (array $item): int => $item['weight']);

        // Assert — ジェネレータ入力でも正常にプールが構築されること
        $this->assertInstanceOf(WeightedPool::class, $pool, 'Generator を渡してもプールが生成されること');
        $result = $pool->draw();
        $this->assertContains($result['name'], ['A', 'B'], 'draw() がプール内のアイテムを返すこと');
    }

    // -------------------------------------------------------------------------
}
