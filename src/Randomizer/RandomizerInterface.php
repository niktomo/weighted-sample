<?php

declare(strict_types=1);

namespace WeightedSample\Randomizer;

interface RandomizerInterface
{
    /**
     * Returns a random integer in [0, max).
     *
     * @param  int $max must be greater than 0
     * @return int a value in [0, max)
     */
    public function next(int $max): int;
}
