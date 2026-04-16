<?php

declare(strict_types=1);

namespace WeightedSample\Randomizer;

use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Deterministic randomizer backed by \Random\Engine\Mt19937.
 *
 * **NEVER USE IN PRODUCTION.** Mt19937 has two critical security weaknesses:
 *
 *   1. **State reconstruction** — an attacker who observes 624 consecutive 32-bit outputs
 *      can fully reconstruct the 19,937-bit internal state and predict every future value.
 *      In a web context, even a handful of draw results in an HTTP response may be enough
 *      to narrow the seed space to a brute-forceable range.
 *
 *   2. **Seed predictability** — seeds derived from timestamps or sequential counters
 *      (e.g. `time()`, `microtime()`) reduce the effective key space to milliseconds or
 *      seconds, making brute-force trivial.
 *
 * Use SeededRandomizer ONLY for:
 *   - Unit / feature tests (deterministic, reproducible draws)
 *   - Offline simulations (replay with a stored seed)
 *
 * For all production draws, inject SecureRandomizer (the default) instead.
 *
 * Seed must be a non-negative 32-bit integer in [0, 4 294 967 295].
 * PHP's Mt19937 accepts a 64-bit int but truncates silently to uint32 internally;
 * values outside [0, 4294967295] are therefore rejected here to prevent
 * two different seeds from producing the same sequence.
 *
 * @see SecureRandomizer for production use
 */
final readonly class SeededRandomizer implements RandomizerInterface
{
    /** Maximum seed value: 2^32 − 1 (unsigned 32-bit). */
    private const MAX_SEED = 4_294_967_295;

    private Randomizer $randomizer;

    /**
     * @internal Do not inject into production code; use SecureRandomizer instead.
     * @see SecureRandomizer
     */
    public function __construct(int $seed)
    {
        if ($seed < 0 || $seed > self::MAX_SEED) {
            throw new InvalidArgumentException(
                "Seed must be in [0, 4294967295] (unsigned 32-bit); {$seed} given.",
            );
        }

        $this->randomizer = new Randomizer(new Mt19937($seed));
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
