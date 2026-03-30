<?php

declare(strict_types=1);

namespace WeightedSample\Selector;

use WeightedSample\Randomizer\RandomizerInterface;

interface SelectorInterface
{
    /**
     * Build a selector from a list of positive integer weights.
     *
     * @param list<int> $weights
     */
    public static function build(array $weights): static;

    /**
     * Pick one index from [0, n) using the given randomizer.
     */
    public function pick(RandomizerInterface $randomizer): int;
}
