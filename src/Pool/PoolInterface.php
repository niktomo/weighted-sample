<?php

declare(strict_types=1);

namespace WeightedSample\Pool;

/**
 * @template T
 */
interface PoolInterface
{
    /**
     * Draws one item from the pool according to its weight.
     *
     * @return T
     */
    public function draw(): mixed;
}
