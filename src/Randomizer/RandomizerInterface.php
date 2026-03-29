<?php

declare(strict_types=1);

namespace WeightedSample\Randomizer;

interface RandomizerInterface
{
    /**
     * Returns a random integer in [0, max).
     */
    public function next(int $max): int;
}
