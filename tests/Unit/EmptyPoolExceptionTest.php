<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WeightedSample\Exception\EmptyPoolException;

class EmptyPoolExceptionTest extends TestCase
{
    public function test_is_runtime_exception(): void
    {
        // Arrange
        $exception = new EmptyPoolException('pool is empty');

        // Assert
        $this->assertInstanceOf(
            \RuntimeException::class,
            $exception,
            'EmptyPoolException が RuntimeException を継承していること',
        );
    }

    public function test_carries_message(): void
    {
        // Arrange
        $exception = new EmptyPoolException('pool is empty');

        // Assert
        $this->assertSame(
            'pool is empty',
            $exception->getMessage(),
            '渡したメッセージがそのまま取得できること',
        );
    }
}
