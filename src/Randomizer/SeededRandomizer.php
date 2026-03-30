<?php

declare(strict_types=1);

namespace WeightedSample\Randomizer;

final readonly class SeededRandomizer implements RandomizerInterface
{
    private \Random\Randomizer $randomizer;

    public function __construct(?int $seed = null)
    {
        $engine = $seed !== null
            ? new \Random\Engine\Mt19937($seed)
            : new \Random\Engine\Secure();

        $this->randomizer = new \Random\Randomizer($engine);
    }

    public function next(int $max): int
    {
        return $this->randomizer->getInt(0, $max - 1);
    }
}
