<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\AliasTableSelector;
use WeightedSample\Selector\SelectorInterface;

class AliasTableSelectorTest extends TestCase
{
    public function test_implements_selector_interface(): void
    {
        // Arrange & Act
        $selector = AliasTableSelector::build([10, 90]);

        // Assert
        $this->assertInstanceOf(SelectorInterface::class, $selector, 'AliasTableSelector が SelectorInterface を実装していること');
    }

    public function test_pick_returns_valid_index_range(): void
    {
        // Arrange
        $selector   = AliasTableSelector::build([10, 30, 60]);
        $randomizer = new SeededRandomizer(42);

        // Act & Assert — 100回引いてすべて有効なインデックスであること
        $result1 = $selector->pick($randomizer);
        $result2 = $selector->pick($randomizer);
        $result3 = $selector->pick($randomizer);
        $this->assertContains($result1, [0, 1, 2], '1回目の pick が有効なインデックスを返すこと');
        $this->assertContains($result2, [0, 1, 2], '2回目の pick が有効なインデックスを返すこと');
        $this->assertContains($result3, [0, 1, 2], '3回目の pick が有効なインデックスを返すこと');
    }

    public function test_single_item_always_returns_zero(): void
    {
        // Arrange — アイテムが1つのとき prob[0]=1.0 なのでコインフリップに関わらず常に 0
        $selector = AliasTableSelector::build([100]);

        // Act & Assert
        $this->assertSame(0, $selector->pick($this->sequenceRandomizer(0, 0)), 'アイテムが1つのとき pick は常に 0 を返すこと（1回目）');
        $this->assertSame(0, $selector->pick($this->sequenceRandomizer(0, PHP_INT_MAX - 1)), 'アイテムが1つのとき pick は常に 0 を返すこと（2回目）');
    }

    public function test_pick_returns_item_index_when_coin_is_below_threshold(): void
    {
        // Arrange — weights=[10, 90]: prob[0]=0.2, alias[0]=1
        // column=0, coin_int=0 → coin_float≈0 < 0.2 → index 0
        $selector = AliasTableSelector::build([10, 90]);

        // Act
        $result = $selector->pick($this->sequenceRandomizer(0, 0));

        // Assert
        $this->assertSame(0, $result, 'column=0 でコインがしきい値を下回るとき index 0 が返ること');
    }

    public function test_pick_returns_alias_index_when_coin_exceeds_threshold(): void
    {
        // Arrange — weights=[10, 90]: prob[0]=0.2, alias[0]=1
        // column=0, coin_int=PHP_INT_MAX-1 → coin_float≈1.0 >= 0.2 → alias[0]=1
        $selector = AliasTableSelector::build([10, 90]);

        // Act
        $result = $selector->pick($this->sequenceRandomizer(0, PHP_INT_MAX - 1));

        // Assert
        $this->assertSame(1, $result, 'column=0 でコインがしきい値を超えるとき alias index 1 が返ること');
    }

    /**
     * @param int ...$values
     */
    private function sequenceRandomizer(int ...$values): RandomizerInterface
    {
        return new class (array_values($values)) implements RandomizerInterface {
            private int $position = 0;

            /** @param list<int> $values */
            public function __construct(private readonly array $values)
            {
            }

            public function next(int $max): int
            {
                return $this->values[$this->position++];
            }
        };
    }
}
