<?php

declare(strict_types=1);

namespace WeightedSample\Internal;

use WeightedSample\Randomizer\RandomizerInterface;
use WeightedSample\Selector\SelectorInterface;

/**
 * Wraps a SelectorInterface built from a compacted weight list,
 * translating the compact 0-based index back to the original index.
 *
 * Used by RebuildSelectorBuilder to maintain stable original indices
 * after zero-weight entries are filtered out for rebuild.
 *
 * @see RebuildSelectorBuilder
 *
 * @internal
 */
final readonly class MappedSelector implements SelectorInterface
{
    /**
     * @param list<int> $indexMap compact index → original index
     */
    public function __construct(
        private SelectorInterface $inner,
        private array $indexMap,
    ) {
    }

    public function pick(RandomizerInterface $randomizer): int
    {
        return $this->indexMap[$this->inner->pick($randomizer)];
    }
}
