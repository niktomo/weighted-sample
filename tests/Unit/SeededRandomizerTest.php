<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Randomizer\SeededRandomizer;

class SeededRandomizerTest extends TestCase
{
    public function test_implements_randomizer_interface(): void
    {
        // Arrange
        $randomizer = new SeededRandomizer(42);

        // Assert
        $this->assertInstanceOf(
            RandomizerInterface::class,
            $randomizer,
            'SeededRandomizer が RandomizerInterface を実装していること',
        );
    }

    public function test_no_seed_produces_values_within_range(): void
    {
        // Arrange — seed なし（デフォルト: Secure エンジン）
        $randomizer = new SeededRandomizer();

        // Act & Assert — 1000回試行してすべて範囲内であることを確認
        for ($i = 0; $i < 1000; $i++) {
            $result = $randomizer->next(100);
            $this->assertGreaterThanOrEqual(0, $result, 'seed なしの next() が 0 以上を返すこと');
            $this->assertLessThan(100, $result, 'seed なしの next() が max 未満を返すこと');
        }
    }

    public function test_same_seed_produces_same_sequence(): void
    {
        // Arrange
        $a = new SeededRandomizer(12345);
        $b = new SeededRandomizer(12345);

        // Act
        $seqA = [$a->next(100), $a->next(100), $a->next(100)];
        $seqB = [$b->next(100), $b->next(100), $b->next(100)];

        // Assert
        $this->assertSame($seqA, $seqB, '同じシードから生成した乱数列が一致すること');
    }

    public function test_different_seeds_produce_different_sequences(): void
    {
        // Arrange
        $a = new SeededRandomizer(1);
        $b = new SeededRandomizer(2);

        // Act
        $seqA = [$a->next(1000), $a->next(1000), $a->next(1000)];
        $seqB = [$b->next(1000), $b->next(1000), $b->next(1000)];

        // Assert
        $this->assertNotSame($seqA, $seqB, '異なるシードは異なる乱数列を生成すること');
    }

    public function test_next_returns_value_within_range(): void
    {
        // Arrange
        $randomizer = new SeededRandomizer(99);

        // Act & Assert
        for ($i = 0; $i < 1000; $i++) {
            $result = $randomizer->next(50);
            $this->assertGreaterThanOrEqual(0, $result, 'next() の戻り値が 0 以上であること');
            $this->assertLessThan(50, $result, 'next() の戻り値が max 未満であること');
        }
    }

    public function test_next_with_max_one_always_returns_zero(): void
    {
        // Arrange
        $randomizer = new SeededRandomizer(1);

        // Act & Assert — [0, 1) の範囲は 0 のみ
        $this->assertSame(0, $randomizer->next(1), 'next(1) は常に 0 を返すこと');
        $this->assertSame(0, $randomizer->next(1), 'next(1) の2回目も 0 を返すこと');
        $this->assertSame(0, $randomizer->next(1), 'next(1) の3回目も 0 を返すこと');
    }

    public function test_instances_are_independent(): void
    {
        // Arrange
        $a = new SeededRandomizer(777);
        $b = new SeededRandomizer(777);

        // Act — a を3回消費しても b の列に影響しないこと
        $a->next(100);
        $a->next(100);
        $a->next(100);

        $firstB = $b->next(100);
        $firstA = (new SeededRandomizer(777))->next(100);

        // Assert
        $this->assertSame($firstA, $firstB, 'インスタンスが独立しており、他インスタンスの消費に影響されないこと');
    }
}
