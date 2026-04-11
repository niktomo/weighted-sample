<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use WeightedSample\Internal\MappedSelector;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Selector\SelectorInterface;

class MappedSelectorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // インターフェース
    // -------------------------------------------------------------------------

    public function test_implements_selector_interface(): void
    {
        // Arrange & Act
        $mapped = new MappedSelector($this->fixedSelector(0), [0]);

        // Assert
        $this->assertInstanceOf(SelectorInterface::class, $mapped, 'MappedSelector が SelectorInterface を実装していること');
    }

    // -------------------------------------------------------------------------
    // compact index → original index 変換
    // -------------------------------------------------------------------------

    public function test_translates_compact_index_to_original_index(): void
    {
        // Arrange — inner が compact 1 を返す; indexMap[1] = 4
        $inner    = $this->fixedSelector(1);
        $indexMap = [2, 4];  // compact 0→2, compact 1→4
        $mapped   = new MappedSelector($inner, $indexMap);

        // Act
        $result = $mapped->pick($this->dummyRandomizer());

        // Assert
        $this->assertSame(4, $result, 'compact index 1 が original index 4 に変換されること');
    }

    public function test_translates_first_compact_index(): void
    {
        // Arrange — inner が compact 0 を返す; indexMap[0] = 3
        $inner    = $this->fixedSelector(0);
        $indexMap = [3, 7, 11];
        $mapped   = new MappedSelector($inner, $indexMap);

        // Act & Assert
        $this->assertSame(3, $mapped->pick($this->dummyRandomizer()), 'compact index 0 が original index 3 に変換されること');
    }

    public function test_single_item_map_returns_original_index(): void
    {
        // Arrange — 最後の 1 アイテムのみ残っているケース: compact 0 → original 5
        $inner    = $this->fixedSelector(0);
        $indexMap = [5];
        $mapped   = new MappedSelector($inner, $indexMap);

        // Act & Assert
        $this->assertSame(5, $mapped->pick($this->dummyRandomizer()), '1アイテムのみのマップで original index 5 が返ること');
    }

    public function test_identity_map_returns_index_unchanged(): void
    {
        // Arrange — compact == original（まだ除外アイテムなし）
        $inner    = $this->fixedSelector(2);
        $indexMap = [0, 1, 2, 3];
        $mapped   = new MappedSelector($inner, $indexMap);

        // Act & Assert
        $this->assertSame(2, $mapped->pick($this->dummyRandomizer()), 'アイデンティティマップでは compact index がそのまま返ること');
    }

    public function test_delegates_pick_to_inner_selector(): void
    {
        // Arrange — inner が呼ばれることを確認するため 3 種の compact index を試す
        $indexMap = [10, 20, 30];

        foreach ([0, 1, 2] as $compactIndex) {
            $mapped = new MappedSelector($this->fixedSelector($compactIndex), $indexMap);
            $result = $mapped->pick($this->dummyRandomizer());

            $this->assertSame(
                $indexMap[$compactIndex],
                $result,
                "compact index {$compactIndex} が original index {$indexMap[$compactIndex]} に変換されること",
            );
        }
    }

    // -------------------------------------------------------------------------
    // ヘルパー
    // -------------------------------------------------------------------------

    private function fixedSelector(int $compactIndex): SelectorInterface
    {
        return new class ($compactIndex) implements SelectorInterface {
            public function __construct(private readonly int $compactIndex)
            {
            }

            public function pick(RandomizerInterface $randomizer): int
            {
                return $this->compactIndex;
            }
        };
    }

    private function dummyRandomizer(): RandomizerInterface
    {
        return new class () implements RandomizerInterface {
            public function next(int $max): int
            {
                return 0;
            }
        };
    }
}
