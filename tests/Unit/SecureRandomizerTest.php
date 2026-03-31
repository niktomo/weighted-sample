<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Randomizer\SecureRandomizer;

class SecureRandomizerTest extends TestCase
{
    public function test_implements_randomizer_interface(): void
    {
        // Arrange & Act
        $randomizer = new SecureRandomizer();

        // Assert
        $this->assertInstanceOf(
            RandomizerInterface::class,
            $randomizer,
            'SecureRandomizer が RandomizerInterface を実装していること',
        );
    }

    public function test_next_returns_values_within_range(): void
    {
        // Arrange
        $randomizer = new SecureRandomizer();

        // Act & Assert — 1000回試行してすべて範囲内であることを確認
        for ($i = 0; $i < 1000; $i++) {
            $result = $randomizer->next(100);
            $this->assertGreaterThanOrEqual(0, $result, 'next() が 0 以上を返すこと');
            $this->assertLessThan(100, $result, 'next() が max 未満を返すこと');
        }
    }

    public function test_next_with_max_one_always_returns_zero(): void
    {
        // Arrange
        $randomizer = new SecureRandomizer();

        // Act & Assert — [0, 1) の範囲は 0 のみ
        $this->assertSame(0, $randomizer->next(1), 'next(1) は常に 0 を返すこと');
        $this->assertSame(0, $randomizer->next(1), 'next(1) の2回目も 0 を返すこと');
        $this->assertSame(0, $randomizer->next(1), 'next(1) の3回目も 0 を返すこと');
    }

    public function test_instances_are_independent(): void
    {
        // Arrange — 2インスタンスが独立して動作すること（片方の消費が影響しない）
        $a = new SecureRandomizer();
        $b = new SecureRandomizer();

        // Act — a を消費しても b の生成は独立していること
        for ($i = 0; $i < 10; $i++) {
            $a->next(100);
        }

        $result = $b->next(100);

        // Assert
        $this->assertGreaterThanOrEqual(0, $result, 'b インスタンスが a の消費に影響されず 0 以上を返すこと');
        $this->assertLessThan(100, $result, 'b インスタンスが a の消費に影響されず max 未満を返すこと');
    }
}
