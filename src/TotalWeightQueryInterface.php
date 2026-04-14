<?php

declare(strict_types=1);

namespace WeightedSample;

/**
 * Provides a read-only query for the current total weight.
 *
 * Separated from ItemExclusionObserverInterface (ISP) so that callers
 * needing only the exclusion notification do not depend on the weight query.
 *
 * Must run in O(1).
 *
 * @see ItemExclusionObserverInterface for the complementary exclusion-notification contract
 */
interface TotalWeightQueryInterface
{
    /**
     * Returns the total weight of all remaining (non-excluded) items.
     */
    public function totalWeight(): int;
}
