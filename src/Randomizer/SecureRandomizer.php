<?php

declare(strict_types=1);

namespace WeightedSample\Randomizer;

use InvalidArgumentException;
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

    /**
     * Initialises the Randomizer with \Random\Engine\Secure (OS CSPRNG).
     *
     * \Random\Engine\Secure uses getrandom(2) (Linux 3.17+), arc4random_buf(3)
     * (macOS/BSD), or BCryptGenRandom (Windows) — always available on PHP 8.2+
     * and requires no seeding.  The constructor cannot throw.
     */
    public function __construct()
    {
        $this->randomizer = new Randomizer(new Secure());
    }

    public function next(int $max): int
    {
        if ($max <= 0) {
            throw new InvalidArgumentException(
                "max must be greater than 0; {$max} given.",
            );
        }

        return $this->randomizer->getInt(0, $max - 1);
    }
}
