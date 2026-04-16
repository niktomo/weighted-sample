<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use InvalidArgumentException;
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

    public function test_next_throws_on_zero_max(): void
    {
        // Arrange
        $randomizer = new SeededRandomizer(42);

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max must be greater than 0');
        $randomizer->next(0);
    }

    public function test_next_throws_on_negative_max(): void
    {
        // Arrange
        $randomizer = new SeededRandomizer(42);

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('max must be greater than 0');
        $randomizer->next(-1);
    }

    public function test_seed_zero_is_valid(): void
    {
        // Arrange & Act
        $randomizer = new SeededRandomizer(0);

        // Assert
        $this->assertSame(0, $randomizer->next(1), 'seed=0 は有効な最小値であること');
    }

    public function test_seed_one_below_max_is_valid(): void
    {
        // Arrange & Act — 4294967294 = 2^32 - 2 は有効な境界直下の値
        $randomizer = new SeededRandomizer(4_294_967_294);

        // Assert
        $this->assertInstanceOf(
            RandomizerInterface::class,
            $randomizer,
            'seed=4294967294 (MAX_SEED - 1) は有効であること',
        );
    }

    public function test_seed_max_uint32_is_valid(): void
    {
        // Arrange & Act — 4294967295 = 2^32 - 1 は有効な最大値
        $randomizer = new SeededRandomizer(4_294_967_295);

        // Assert
        $this->assertInstanceOf(
            RandomizerInterface::class,
            $randomizer,
            'seed=4294967295 (unsigned 32-bit max) は有効であること',
        );
    }

    public function test_negative_seed_throws(): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seed must be in [0, 4294967295] (unsigned 32-bit); -1 given.');

        // Act
        new SeededRandomizer(-1);
    }

    public function test_seed_exceeding_uint32_throws(): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seed must be in [0, 4294967295] (unsigned 32-bit); 4294967296 given.');

        // Act
        new SeededRandomizer(4_294_967_296);
    }
}
