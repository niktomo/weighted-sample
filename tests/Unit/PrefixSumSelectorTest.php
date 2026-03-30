<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Selector\PrefixSumSelector;
use WeightedSample\Selector\SelectorInterface;

class PrefixSumSelectorTest extends TestCase
{
    public function test_implements_selector_interface(): void
    {
        // Arrange & Act
        $selector = PrefixSumSelector::build([10, 90]);

        // Assert
        $this->assertInstanceOf(SelectorInterface::class, $selector, 'PrefixSumSelector が SelectorInterface を実装していること');
    }

    public function test_pick_returns_first_index_when_rand_is_zero(): void
    {
        // Arrange
        $selector = PrefixSumSelector::build([10, 90]);

        // Act
        $result = $selector->pick($this->fixedRandomizer(0));

        // Assert
        $this->assertSame(0, $result, 'rand=0 のとき最初のインデックス 0 が返ること');
    }

    public function test_pick_returns_last_index_when_rand_is_total_minus_one(): void
    {
        // Arrange — total=100, rand=99
        $selector = PrefixSumSelector::build([10, 90]);

        // Act
        $result = $selector->pick($this->fixedRandomizer(99));

        // Assert
        $this->assertSame(1, $result, 'rand=total-1 のとき最後のインデックス 1 が返ること');
    }

    public function test_pick_returns_valid_index_for_single_item(): void
    {
        // Arrange
        $selector = PrefixSumSelector::build([42]);

        // Act
        $result = $selector->pick($this->fixedRandomizer(0));

        // Assert
        $this->assertSame(0, $result, 'アイテムが1つのとき常にインデックス 0 が返ること');
    }

    private function fixedRandomizer(int $value): RandomizerInterface
    {
        return new class ($value) implements RandomizerInterface {
            public function __construct(private readonly int $value)
            {
            }

            public function next(int $max): int
            {
                return $this->value;
            }
        };
    }
}
