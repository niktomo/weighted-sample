<?php

declare(strict_types=1);

namespace WeightedSample\Exception;

/**
 * Thrown when draw() is called on an empty or exhausted pool.
 *
 * @see AllItemsFilteredException for the construction-time variant (all items filtered out)
 */
class EmptyPoolException extends \RuntimeException
{
}
