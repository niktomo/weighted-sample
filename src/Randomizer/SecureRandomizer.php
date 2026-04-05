<?php

declare(strict_types=1);

namespace WeightedSample\Randomizer;

use Random\Engine\Secure;
use Random\Randomizer;

/**
 * Cryptographically secure randomizer backed by \Random\Engine\Secure.
 *
 * This is the default randomizer for all pools.
 * Use this in production draws — it is not reproducible between runs.
 */
final readonly class SecureRandomizer implements RandomizerInterface
{
    private Randomizer $randomizer;

    public function __construct()
    {
        $this->randomizer = new Randomizer(new Secure());
    }

    public function next(int $max): int
    {
        return $this->randomizer->getInt(0, $max - 1);
    }
}
