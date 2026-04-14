<?php

declare(strict_types=1);

namespace WeightedSample\Exception;

/**
 * Thrown when draw() is called on an empty or exhausted pool.
 *
 * Note: construction-time failure (all items filtered out by of()) throws
 * InvalidArgumentException, not EmptyPoolException — the two are unrelated
 * in the exception hierarchy and represent distinct failure modes.
 */
class EmptyPoolException extends \RuntimeException
{
}
