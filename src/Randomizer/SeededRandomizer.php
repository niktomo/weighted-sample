<?php

declare(strict_types=1);

namespace WeightedSample\Randomizer;

/**
 * Deterministic randomizer backed by \Random\Engine\Mt19937.
 *
 * Use this only for testing or reproducible simulations — never for production draws.
 * Mt19937 is NOT cryptographically secure.
 *
 * @see SecureRandomizer for production use
 */
final readonly class SeededRandomizer implements RandomizerInterface
{
    private \Random\Randomizer $randomizer;

    public function __construct(int $seed)
    {
        $this->randomizer = new \Random\Randomizer(new \Random\Engine\Mt19937($seed));
    }

    public function next(int $max): int
    {
        return $this->randomizer->getInt(0, $max - 1);
    }
}
