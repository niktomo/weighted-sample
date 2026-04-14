<?php

declare(strict_types=1);

namespace WeightedSample\Tests\Support;

use InvalidArgumentException;
use WeightedSample\Randomizer\RandomizerInterface;

/**
 * Test-only randomizer that plays back a fixed sequence of integers.
 *
 * Each value must satisfy 0 ≤ value < max passed to next();
 * an InvalidArgumentException is thrown if this contract is violated.
 */
final class SequenceRandomizer implements RandomizerInterface
{
    private int $position = 0;

    /** @param list<int> $values */
    public function __construct(private readonly array $values)
    {
    }

    public function next(int $max): int
    {
        if (!isset($this->values[$this->position])) {
            throw new \OutOfBoundsException(
                "SequenceRandomizer: sequence exhausted at position {$this->position}.",
            );
        }

        $value = $this->values[$this->position++];

        if ($value < 0 || $value >= $max) {
            throw new InvalidArgumentException(
                "SequenceRandomizer: value {$value} out of range [0, {$max})",
            );
        }

        return $value;
    }
}
