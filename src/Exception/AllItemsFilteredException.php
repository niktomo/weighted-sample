<?php

declare(strict_types=1);

namespace WeightedSample\Exception;

/**
 * Thrown during pool construction when no items remain after filtering.
 *
 * Distinguishes construction-time failure (all items were filtered out by weight/count rules)
 * from runtime exhaustion (items were drawn until the pool was empty).
 *
 * Extends EmptyPoolException for backward compatibility:
 * existing catch (EmptyPoolException) blocks continue to work unchanged.
 */
final class AllItemsFilteredException extends EmptyPoolException
{
}
