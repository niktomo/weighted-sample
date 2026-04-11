<?php

declare(strict_types=1);

namespace WeightedSample\Builder;

use WeightedSample\Selector\SelectorInterface;

/**
 * Pairs a SelectorInterface with its SelectorBuilderInterface.
 *
 * FenwickSelectorBundleFactory guarantees that $selector and $builder
 * share the same underlying FenwickTreeSelector instance, so subtract()
 * calls are immediately reflected in currentSelector()->pick().
 */
final readonly class SelectorBundle
{
    public function __construct(
        public readonly SelectorInterface $selector,
        public readonly SelectorBuilderInterface $builder,
    ) {
    }
}
