<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use WeightedSample\Builder\SelectorBundle;
use WeightedSample\Builder\SelectorBuilderInterface;
use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Selector\SelectorInterface;

class SelectorBundleTest extends TestCase
{
    // -------------------------------------------------------------------------
    // 構築
    // -------------------------------------------------------------------------

    public function test_holds_selector_and_builder(): void
    {
        // Arrange
        $selector = $this->makeSelector();
        $builder  = $this->makeBuilder($selector);

        // Act
        $bundle = new SelectorBundle($selector, $builder);

        // Assert
        $this->assertSame($selector, $bundle->selector, 'bundle->selector が渡した SelectorInterface を返すこと');
        $this->assertSame($builder, $bundle->builder, 'bundle->builder が渡した SelectorBuilderInterface を返すこと');
    }

    public function test_properties_are_readonly(): void
    {
        // Assert — Reflection で readonly が設定されていることを確認
        $selectorProp = new \ReflectionProperty(SelectorBundle::class, 'selector');
        $builderProp  = new \ReflectionProperty(SelectorBundle::class, 'builder');

        $this->assertTrue($selectorProp->isReadOnly(), 'selector プロパティが readonly であること');
        $this->assertTrue($builderProp->isReadOnly(), 'builder プロパティが readonly であること');
    }

    // -------------------------------------------------------------------------
    // ヘルパー
    // -------------------------------------------------------------------------

    private function makeSelector(): SelectorInterface
    {
        return new class () implements SelectorInterface {
            public function pick(RandomizerInterface $randomizer): int
            {
                return 0;
            }
        };
    }

    private function makeBuilder(SelectorInterface $selector): SelectorBuilderInterface
    {
        return new class ($selector) implements SelectorBuilderInterface {
            public function __construct(private SelectorInterface $selector)
            {
            }

            public function subtract(int $index): void
            {
            }

            public function currentSelector(): SelectorInterface
            {
                return $this->selector;
            }

            public function totalWeight(): int
            {
                return 0;
            }
        };
    }
}
