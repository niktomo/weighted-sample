<?php

declare(strict_types=1);

/**
 * Benchmark: build time, draw time, and total throughput for all pool types.
 *
 * Measured dimensions:
 *   - Build only  : time to construct a pool (Pool::of()) without any draws
 *   - Draw only   : time to call draw() on an already-built pool
 *   - Total       : build + all draws combined (realistic end-to-end scenario)
 *
 * Sections:
 *   1. WeightedPool  — immutable pool; build once, draw repeatedly
 *   2. DestructivePool — stateful; each trial rebuilds and draws until empty
 *   3. BoxPool       — stateful; each trial rebuilds and draws until stock runs out
 *   4. Selector comparison — PrefixSum O(log n) vs AliasTable O(1) across item counts
 */

require_once __DIR__ . '/../vendor/autoload.php';

use WeightedSample\Pool\BoxPool;
use WeightedSample\Pool\DestructivePool;
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\AliasTableSelector;
use WeightedSample\Selector\PrefixSumSelector;

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

/** Trials for build-only measurement (each trial = one Pool::of() call). */
const BUILD_TRIALS = 10_000;

/** Draws per trial for WeightedPool draw-only measurement. */
const WEIGHTED_DRAWS_PER_TRIAL = 100_000;

/** Trials for DestructivePool / BoxPool total measurement. */
const POOL_TRIALS = 10_000;

/** Item count for selector comparison benchmark. */
const SELECTOR_PICKS = 1_000_000;

// ---------------------------------------------------------------------------
// Items used across all sections
// ---------------------------------------------------------------------------

/** @var list<array{name: string, weight: int}> */
$weightedItems = [
    ['name' => 'SSR',    'weight' => 1],
    ['name' => 'SR',     'weight' => 9],
    ['name' => 'R',      'weight' => 90],
];

/** @var list<array{name: string, weight: int}> */
$destructiveItems = [
    ['name' => 'Gold',   'weight' => 10],
    ['name' => 'Silver', 'weight' => 30],
    ['name' => 'Bronze', 'weight' => 60],
];

/** @var list<array{name: string, weight: int, stock: int}> */
$boxItems = [
    ['name' => 'Gold',   'weight' => 10, 'stock' => 1],
    ['name' => 'Silver', 'weight' => 30, 'stock' => 3],
    ['name' => 'Bronze', 'weight' => 60, 'stock' => 6],
];

// ---------------------------------------------------------------------------
// Helper: measure execution time of a callable in milliseconds
// ---------------------------------------------------------------------------

/**
 * @param callable(): void $callable
 */
function measureMs(callable $callable): float
{
    $start = hrtime(true);
    $callable();

    return (hrtime(true) - $start) / 1_000_000;
}

/**
 * @param callable(): void $callable
 */
function measureMsPerOp(callable $callable, int $operations): float
{
    return measureMs($callable) / $operations;
}

// ---------------------------------------------------------------------------
// Header
// ---------------------------------------------------------------------------

function printSectionHeader(string $title): void
{
    echo "\n";
    echo str_repeat('=', 72) . "\n";
    echo "  {$title}\n";
    echo str_repeat('=', 72) . "\n";
    printf("%-16s %16s %16s %16s\n", 'Metric', 'Total (ms)', 'Per-op (µs)', 'Ops');
    echo str_repeat('-', 68) . "\n";
}

function printRow(string $label, float $totalMs, int $ops): void
{
    $perOpUs = $totalMs / $ops * 1_000;
    printf("%-16s %16.3f %16.3f %16d\n", $label, $totalMs, $perOpUs, $ops);
}

// ===========================================================================
// Section 1: WeightedPool
// ===========================================================================

printSectionHeader('WeightedPool (SSR=1%, SR=9%, R=90%)');

// --- Build only ---
$buildMs = measureMs(function () use ($weightedItems): void {
    for ($i = 0; $i < BUILD_TRIALS; $i++) {
        WeightedPool::of(
            $weightedItems,
            fn (array $item): int => $item['weight'],
            randomizer: new SeededRandomizer(42),
        );
    }
});
printRow('Build only', $buildMs, BUILD_TRIALS);

// --- Draw only (PrefixSum) ---
$prebuiltPrefix = WeightedPool::of(
    $weightedItems,
    fn (array $item): int => $item['weight'],
    randomizer: new SeededRandomizer(42),
);
$drawPrefixMs = measureMs(function () use ($prebuiltPrefix): void {
    for ($i = 0; $i < WEIGHTED_DRAWS_PER_TRIAL; $i++) {
        $prebuiltPrefix->draw();
    }
});
printRow('Draw (PrefixSum)', $drawPrefixMs, WEIGHTED_DRAWS_PER_TRIAL);

// --- Draw only (Alias) ---
$prebuiltAlias = WeightedPool::of(
    $weightedItems,
    fn (array $item): int => $item['weight'],
    selectorClass: AliasTableSelector::class,
    randomizer: new SeededRandomizer(42),
);
$drawAliasMs = measureMs(function () use ($prebuiltAlias): void {
    for ($i = 0; $i < WEIGHTED_DRAWS_PER_TRIAL; $i++) {
        $prebuiltAlias->draw();
    }
});
printRow('Draw (Alias)', $drawAliasMs, WEIGHTED_DRAWS_PER_TRIAL);

// --- Total: 1 build + N draws (PrefixSum) ---
$totalPrefixMs = measureMs(function () use ($weightedItems): void {
    for ($i = 0; $i < BUILD_TRIALS; $i++) {
        $pool = WeightedPool::of(
            $weightedItems,
            fn (array $item): int => $item['weight'],
            randomizer: new SeededRandomizer(42),
        );
        for ($draw = 0; $draw < 100; $draw++) {
            $pool->draw();
        }
    }
});
printRow('Total (B+100D)', $totalPrefixMs, BUILD_TRIALS);

// ===========================================================================
// Section 2: DestructivePool
// ===========================================================================

printSectionHeader('DestructivePool (Gold=10%, Silver=30%, Bronze=60%)');

/** Number of draws per pool until empty. */
$destructiveDrawsPerPool = count($destructiveItems);

// --- Build only ---
$buildMs = measureMs(function () use ($destructiveItems): void {
    for ($i = 0; $i < BUILD_TRIALS; $i++) {
        DestructivePool::of(
            $destructiveItems,
            fn (array $item): int => $item['weight'],
            randomizer: new SeededRandomizer(42),
        );
    }
});
printRow('Build only', $buildMs, BUILD_TRIALS);

// --- Draw only (from pre-built pool, draw all) ---
$drawTotalMs = 0.0;
for ($trial = 0; $trial < POOL_TRIALS; $trial++) {
    $pool = DestructivePool::of(
        $destructiveItems,
        fn (array $item): int => $item['weight'],
        randomizer: new SeededRandomizer($trial),
    );
    $drawTotalMs += measureMs(function () use ($pool, $destructiveDrawsPerPool): void {
        for ($draw = 0; $draw < $destructiveDrawsPerPool; $draw++) {
            $pool->draw();
        }
    });
}
printRow('Draw only (all)', $drawTotalMs, POOL_TRIALS * $destructiveDrawsPerPool);

// --- Total: build + draw all (per trial) ---
$totalMs = measureMs(function () use ($destructiveItems, $destructiveDrawsPerPool): void {
    for ($trial = 0; $trial < POOL_TRIALS; $trial++) {
        $pool = DestructivePool::of(
            $destructiveItems,
            fn (array $item): int => $item['weight'],
            randomizer: new SeededRandomizer($trial),
        );
        while (! $pool->isEmpty()) {
            $pool->draw();
        }
    }
});
printRow('Total (B+DrawAll)', $totalMs, POOL_TRIALS);

// ===========================================================================
// Section 3: BoxPool
// ===========================================================================

/** @var int $boxDrawsPerPool Total stock count per box. */
$boxDrawsPerPool = array_sum(array_column($boxItems, 'stock'));

printSectionHeader("BoxPool (Gold×1, Silver×3, Bronze×6 = {$boxDrawsPerPool} draws/box)");

// --- Build only ---
$buildMs = measureMs(function () use ($boxItems): void {
    for ($i = 0; $i < BUILD_TRIALS; $i++) {
        BoxPool::of(
            $boxItems,
            fn (array $item): int => $item['weight'],
            fn (array $item): int => $item['stock'],
            randomizer: new SeededRandomizer(42),
        );
    }
});
printRow('Build only', $buildMs, BUILD_TRIALS);

// --- Draw only (from pre-built pool, draw all) ---
$drawTotalMs = 0.0;
for ($trial = 0; $trial < POOL_TRIALS; $trial++) {
    $pool = BoxPool::of(
        $boxItems,
        fn (array $item): int => $item['weight'],
        fn (array $item): int => $item['stock'],
        randomizer: new SeededRandomizer($trial),
    );
    $drawTotalMs += measureMs(function () use ($pool): void {
        while (! $pool->isEmpty()) {
            $pool->draw();
        }
    });
}
printRow('Draw only (all)', $drawTotalMs, POOL_TRIALS * $boxDrawsPerPool);

// --- Total: build + draw all (per trial) ---
$totalMs = measureMs(function () use ($boxItems): void {
    for ($trial = 0; $trial < POOL_TRIALS; $trial++) {
        $pool = BoxPool::of(
            $boxItems,
            fn (array $item): int => $item['weight'],
            fn (array $item): int => $item['stock'],
            randomizer: new SeededRandomizer($trial),
        );
        while (! $pool->isEmpty()) {
            $pool->draw();
        }
    }
});
printRow('Total (B+DrawAll)', $totalMs, POOL_TRIALS);

// ===========================================================================
// Section 4: Selector pick performance vs item count
// ===========================================================================

echo "\n";
echo str_repeat('=', 72) . "\n";
echo '  Selector pick throughput vs item count (' . SELECTOR_PICKS . " picks each)\n";
echo str_repeat('=', 72) . "\n";
printf("%-10s %22s %22s %10s\n", 'Items', 'PrefixSum O(log n) µs', 'Alias O(1) µs', 'Ratio A/P');
echo str_repeat('-', 68) . "\n";

foreach ([3, 10, 30, 50, 100, 200, 500, 1000] as $itemCount) {
    $weights = range(1, $itemCount);

    $prefixSelector = PrefixSumSelector::build($weights);
    $aliasSelector  = AliasTableSelector::build($weights);

    $prefixRandomizer = new SeededRandomizer(42);
    $prefixMs         = measureMs(function () use ($prefixSelector, $prefixRandomizer): void {
        for ($i = 0; $i < SELECTOR_PICKS; $i++) {
            $prefixSelector->pick($prefixRandomizer);
        }
    });

    $aliasRandomizer = new SeededRandomizer(42);
    $aliasMs         = measureMs(function () use ($aliasSelector, $aliasRandomizer): void {
        for ($i = 0; $i < SELECTOR_PICKS; $i++) {
            $aliasSelector->pick($aliasRandomizer);
        }
    });

    $prefixUsPerOp = $prefixMs / SELECTOR_PICKS * 1_000;
    $aliasUsPerOp  = $aliasMs / SELECTOR_PICKS * 1_000;
    $ratio         = $aliasMs > 0 ? $aliasMs / $prefixMs : 0.0;

    printf("%-10d %22.4f %22.4f %10.3fx\n", $itemCount, $prefixUsPerOp, $aliasUsPerOp, $ratio);
}

echo str_repeat('-', 68) . "\n";
echo "Ratio < 1.0 means Alias is faster; > 1.0 means PrefixSum is faster.\n";
echo "\nDone.\n";
