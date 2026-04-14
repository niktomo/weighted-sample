<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Support;

use WeightedSample\Randomizer\RandomizerInterface;

trait RandomizerHelpers
{
    /**
     * Returns a randomizer that always returns min($value, $max - 1).
     * The clamp ensures the returned value stays within [0, $max) as required by RandomizerInterface.
     */
    private function fixedRandomizer(int $value): RandomizerInterface
    {
        return new class ($value) implements RandomizerInterface {
            public function __construct(private readonly int $value)
            {
            }

            public function next(int $max): int
            {
                return min($this->value, $max - 1);
            }
        };
    }

    /**
     * Returns a randomizer that plays back the given sequence of values in order.
     * Each value must satisfy 0 ≤ value < max; an assertion fires if this is violated.
     *
     * @param int ...$values Values to return from successive next() calls.
     */
    private function sequenceRandomizer(int ...$values): RandomizerInterface
    {
        return new SequenceRandomizer(array_values($values));
    }
}
