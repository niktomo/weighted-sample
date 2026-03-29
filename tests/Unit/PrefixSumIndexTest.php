<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeightedSample\Internal\PrefixSumIndex;

class PrefixSumIndexTest extends TestCase
{
    // -------------------------------------------------------------------------
    // pick() — 境界値
    // -------------------------------------------------------------------------

    public function test_pick_zero_returns_first_item(): void
    {
        // Arrange
        $index = new PrefixSumIndex([10, 90]);

        // Act
        $result = $index->pick(0);

        // Assert
        $this->assertSame(0, $result, 'rand=0 のとき最初のアイテムのインデックスが返ること');
    }

    public function test_pick_total_minus_one_returns_last_item(): void
    {
        // Arrange
        $index = new PrefixSumIndex([10, 90]); // total=100

        // Act
        $result = $index->pick(99);

        // Assert
        $this->assertSame(1, $result, 'rand=total-1 のとき最後のアイテムのインデックスが返ること');
    }

    public function test_pick_at_boundary_between_items(): void
    {
        // Arrange
        $index = new PrefixSumIndex([10, 90]); // prefix sums: [10, 100]

        // Act & Assert
        $this->assertSame(0, $index->pick(9), 'rand=9（weight=10 の帯の末尾）は最初のアイテムを返すこと');
        $this->assertSame(1, $index->pick(10), 'rand=10（weight=90 の帯の先頭）は次のアイテムを返すこと');
    }

    public function test_pick_single_item_always_returns_zero(): void
    {
        // Arrange
        $index = new PrefixSumIndex([42]); // total=42

        // Act & Assert
        $this->assertSame(0, $index->pick(0), 'アイテムが1つのとき rand=0 でインデックス 0 が返ること');
        $this->assertSame(0, $index->pick(21), 'アイテムが1つのとき rand=21 でインデックス 0 が返ること');
        $this->assertSame(0, $index->pick(41), 'アイテムが1つのとき rand=total-1 でインデックス 0 が返ること');
    }

    // -------------------------------------------------------------------------
    // total()
    // -------------------------------------------------------------------------

    /** @param list<int> $weights */
    #[DataProvider('totalValues')]
    public function test_total_returns_sum_of_weights(array $weights, int $expected): void
    {
        // Arrange
        $index = new PrefixSumIndex($weights);

        // Assert
        $this->assertSame($expected, $index->total(), "weights の合計が {$expected} であること");
    }

    /** @return list<array{0: list<int>, 1: int}> */
    public static function totalValues(): array
    {
        return [
            [[10, 90],     100],
            [[1, 2, 3],    6],
            [[100],        100],
            [[1, 1, 1, 1], 4],
        ];
    }

    // -------------------------------------------------------------------------
    // バリデーション
    // -------------------------------------------------------------------------

    public function test_throws_on_empty_weights(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        new PrefixSumIndex([]);
    }

    public function test_throws_on_zero_weight(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('weight');

        // Act
        new PrefixSumIndex([10, 0, 5]);
    }

    public function test_throws_on_negative_weight(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('weight');

        // Act
        new PrefixSumIndex([10, -1, 5]);
    }
}
