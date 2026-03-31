<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WeightedSample\Exception\AllItemsFilteredException;
use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Filter\CountedItemFilterInterface;
use WeightedSample\Filter\ItemFilterInterface;
use WeightedSample\Filter\StrictValueFilter;
use WeightedSample\Pool\BoxPool;
use WeightedSample\Pool\DestructivePool;
use WeightedSample\Pool\WeightedPool;

class AllItemsFilteredExceptionTest extends TestCase
{
    // --- 例外クラス階層 ---

    public function test_is_subtype_of_empty_pool_exception(): void
    {
        // Arrange
        $exception = new AllItemsFilteredException('filtered out');

        // Assert
        $this->assertInstanceOf(
            EmptyPoolException::class,
            $exception,
            'AllItemsFilteredException が EmptyPoolException のサブクラスであること（後方互換）',
        );
    }

    public function test_is_runtime_exception(): void
    {
        // Arrange
        $exception = new AllItemsFilteredException('filtered out');

        // Assert
        $this->assertInstanceOf(
            \RuntimeException::class,
            $exception,
            'AllItemsFilteredException が RuntimeException を継承していること',
        );
    }

    // --- WeightedPool 構築時 ---

    public function test_weighted_pool_of_throws_when_empty_array(): void
    {
        // Assert
        $this->expectException(AllItemsFilteredException::class);

        // Act
        WeightedPool::of([], fn (array $item) => $item['weight']);
    }

    public function test_weighted_pool_of_throws_when_all_filtered(): void
    {
        // Arrange
        $items = [['weight' => 0], ['weight' => 0]];

        // Assert
        $this->expectException(AllItemsFilteredException::class);

        // Act
        WeightedPool::of($items, fn (array $item) => $item['weight']);
    }

    public function test_weighted_pool_of_throws_when_strict_filter_rejects(): void
    {
        // Arrange
        $items = [['weight' => 0]];

        // Assert
        $this->expectException(AllItemsFilteredException::class);

        // Act — StrictValueFilter は InvalidArgumentException を投げるが、
        // 全アイテムが拒否された場合の構築失敗は AllItemsFilteredException
        // ※ StrictValueFilter 自体は InvalidArgumentException を投げるので
        //    ここでは weight=0 を PositiveValueFilter で除外するケースを使う
        WeightedPool::of($items, fn (array $item) => $item['weight']);
    }

    // --- DestructivePool 構築時 ---

    public function test_destructive_pool_of_throws_when_empty_array(): void
    {
        // Assert
        $this->expectException(AllItemsFilteredException::class);

        // Act
        DestructivePool::of([], fn (array $item) => $item['weight']);
    }

    public function test_destructive_pool_of_throws_when_all_filtered(): void
    {
        // Arrange
        $items = [['weight' => 0], ['weight' => 0]];

        // Assert
        $this->expectException(AllItemsFilteredException::class);

        // Act
        DestructivePool::of($items, fn (array $item) => $item['weight']);
    }

    // --- BoxPool 構築時 ---

    public function test_box_pool_of_throws_when_empty_array(): void
    {
        // Assert
        $this->expectException(AllItemsFilteredException::class);

        // Act
        BoxPool::of([], fn (array $item) => $item['weight'], fn (array $item) => $item['stock']);
    }

    public function test_box_pool_of_throws_when_all_filtered(): void
    {
        // Arrange
        $items = [['weight' => 0, 'stock' => 1], ['weight' => 0, 'stock' => 1]];

        // Assert
        $this->expectException(AllItemsFilteredException::class);

        // Act
        BoxPool::of($items, fn (array $item) => $item['weight'], fn (array $item) => $item['stock']);
    }

    // --- カスタム always-false フィルター ---

    public function test_weighted_pool_throws_when_custom_filter_rejects_all(): void
    {
        // Arrange — 全アイテムを拒否するカスタムフィルター
        $rejectAll = new class implements ItemFilterInterface {
            public function accepts(mixed $item, int $weight): bool
            {
                return false;
            }
        };
        $items = [['weight' => 10], ['weight' => 20]];

        // Assert
        $this->expectException(AllItemsFilteredException::class);

        // Act
        WeightedPool::of($items, fn (array $item) => $item['weight'], filter: $rejectAll);
    }

    public function test_destructive_pool_throws_when_custom_filter_rejects_all(): void
    {
        // Arrange — 全アイテムを拒否するカスタムフィルター
        $rejectAll = new class implements ItemFilterInterface {
            public function accepts(mixed $item, int $weight): bool
            {
                return false;
            }
        };
        $items = [['weight' => 10], ['weight' => 20]];

        // Assert
        $this->expectException(AllItemsFilteredException::class);

        // Act
        DestructivePool::of($items, fn (array $item) => $item['weight'], filter: $rejectAll);
    }

    public function test_box_pool_throws_when_custom_filter_rejects_all(): void
    {
        // Arrange — 全アイテムを拒否するカスタム CountedItemFilterInterface
        $rejectAll = new class implements CountedItemFilterInterface {
            public function accepts(mixed $item, int $weight): bool
            {
                return false;
            }

            public function acceptsWithCount(mixed $item, int $weight, int $count): bool
            {
                return false;
            }
        };
        $items = [['weight' => 10, 'stock' => 3], ['weight' => 20, 'stock' => 5]];

        // Assert
        $this->expectException(AllItemsFilteredException::class);

        // Act
        BoxPool::of(
            $items,
            fn (array $item) => $item['weight'],
            fn (array $item) => $item['stock'],
            filter: $rejectAll,
        );
    }

    // --- 実行時枯渇は EmptyPoolException のまま（AllItemsFiltered ではない）---

    public function test_draw_on_exhausted_destructive_pool_throws_empty_pool_exception_not_filtered(): void
    {
        // Arrange
        $pool = DestructivePool::of(
            [['weight' => 1]],
            fn (array $item) => $item['weight'],
        );
        $pool->draw(); // 枯渇させる

        // Assert — 実行時枯渇は EmptyPoolException だが AllItemsFilteredException ではない
        $this->expectException(EmptyPoolException::class);

        // Act
        $pool->draw();
    }

    public function test_exhausted_exception_is_not_all_items_filtered(): void
    {
        // Arrange
        $pool = DestructivePool::of(
            [['weight' => 1]],
            fn (array $item) => $item['weight'],
        );
        $pool->draw();

        try {
            $pool->draw();
            $this->fail('EmptyPoolException がスローされること');
        } catch (EmptyPoolException $e) {
            // Assert — 実行時枯渇の例外は AllItemsFilteredException ではないこと
            $this->assertNotInstanceOf(
                AllItemsFilteredException::class,
                $e,
                '実行時の枯渇は AllItemsFilteredException ではなく EmptyPoolException であること',
            );
        }
    }
}
