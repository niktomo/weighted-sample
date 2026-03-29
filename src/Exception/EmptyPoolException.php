<?php

declare(strict_types=1);

namespace WeightedSample\Exception;

/**
 * Thrown when draw() is called on an empty pool,
 * or when no items remain after filtering.
 */
final class EmptyPoolException extends \RuntimeException
{
}
