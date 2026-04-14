<?php

declare(strict_types=1);

/**
 * Benchmark: build time, draw time, and total throughput for all pool types.
 *
 * Sections:
 *   1. WeightedPool  — immutable pool; build once, draw repeatedly
 *   2. BoxPool       — stateful; each trial rebuilds and draws until stock runs out
 *   3. Selector pick throughput — PrefixSum / Alias / Fenwick across item counts
 *   4. Accuracy                 — max deviation from expected across all selectors
 */

require_once __DIR__ . '/../vendor/autoload.php';

use WeightedSample\Builder\FenwickSelectorBundleFactory;
use WeightedSample\Builder\RebuildSelectorBundleFactory;
use WeightedSample\Pool\BoxPool;
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\AliasTableSelector;
use WeightedSample\Selector\AliasTableSelectorFactory;
use WeightedSample\Selector\FenwickTreeSelector;
use WeightedSample\Selector\FenwickTreeSelectorFactory;
use WeightedSample\Selector\PrefixSumSelector;
use WeightedSample\Selector\PrefixSumSelectorFactory;

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const BUILD_TRIALS           = 10_000;
const WEIGHTED_DRAWS_PER_TRIAL = 100_000;
const POOL_TRIALS            = 10_000;
const SELECTOR_PICKS         = 1_000_000;
const ACCURACY_DRAWS_PER_ITEM = 500;

// ---------------------------------------------------------------------------
// Items
// ---------------------------------------------------------------------------

/** @var list<array{name: string, weight: int}> */
$weightedItems = [
    ['name' => 'SSR', 'weight' => 1],
    ['name' => 'SR',  'weight' => 9],
    ['name' => 'R',   'weight' => 90],
];

/** @var list<array{name: string, weight: int, stock: int}> */
$boxItems = [
    ['name' => 'Gold',   'weight' => 10, 'stock' => 1],
    ['name' => 'Silver', 'weight' => 30, 'stock' => 3],
    ['name' => 'Bronze', 'weight' => 60, 'stock' => 6],
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** @param callable(): void $callable */
function measureMs(callable $callable): float
{
    $start = hrtime(true);
    $callable();
    return (hrtime(true) - $start) / 1_000_000;
}

function printSectionHeader(string $title): void
{
    echo "\n" . str_repeat('=', 72) . "\n";
    echo "  {$title}\n";
    echo str_repeat('=', 72) . "\n";
    printf("%-18s %14s %14s %14s\n", 'Metric', 'Total (ms)', 'Per-op (µs)', 'Ops');
    echo str_repeat('-', 64) . "\n";
}

function printRow(string $label, float $totalMs, int $ops): void
{
    $perOpUs = $ops > 0 ? $totalMs / $ops * 1_000 : 0.0;
    printf("%-18s %14.3f %14.3f %14d\n", $label, $totalMs, $perOpUs, $ops);
}

// ===========================================================================
// Section 1: WeightedPool
// ===========================================================================

printSectionHeader('WeightedPool (SSR=1%, SR=9%, R=90%)');

$ms = measureMs(function () use ($weightedItems): void {
    for ($i = 0; $i < BUILD_TRIALS; $i++) {
        WeightedPool::of($weightedItems, fn (array $item): int => $item['weight'], randomizer: new SeededRandomizer(42));
    }
});
printRow('Build only', $ms, BUILD_TRIALS);

foreach ([
    'PrefixSum' => new PrefixSumSelectorFactory(),
    'Alias'     => new AliasTableSelectorFactory(),
] as $name => $factory) {
    $pool = WeightedPool::of($weightedItems, fn (array $item): int => $item['weight'], selectorFactory: $factory, randomizer: new SeededRandomizer(42));
    $ms   = measureMs(function () use ($pool): void {
        for ($i = 0; $i < WEIGHTED_DRAWS_PER_TRIAL; $i++) { $pool->draw(); }
    });
    printRow("Draw ({$name})", $ms, WEIGHTED_DRAWS_PER_TRIAL);
}

$ms = measureMs(function () use ($weightedItems): void {
    for ($i = 0; $i < BUILD_TRIALS; $i++) {
        $pool = WeightedPool::of($weightedItems, fn (array $item): int => $item['weight'], randomizer: new SeededRandomizer(42));
        for ($d = 0; $d < 100; $d++) { $pool->draw(); }
    }
});
printRow('Total (B+100D)', $ms, BUILD_TRIALS);

// ===========================================================================
// Section 2: BoxPool
// ===========================================================================

$boxDrawsPerPool = (int) array_sum(array_column($boxItems, 'stock'));
printSectionHeader("BoxPool (Gold×1, Silver×3, Bronze×6 = {$boxDrawsPerPool} draws/box)");

$ms = measureMs(function () use ($boxItems): void {
    for ($i = 0; $i < BUILD_TRIALS; $i++) {
        BoxPool::of($boxItems, fn (array $item): int => $item['weight'], fn (array $item): int => $item['stock'], randomizer: new SeededRandomizer(42));
    }
});
printRow('Build only', $ms, BUILD_TRIALS);

foreach ([
    'PrefixSum (Rebuild)' => new RebuildSelectorBundleFactory(new PrefixSumSelectorFactory()),
    'Fenwick'             => new FenwickSelectorBundleFactory(),
] as $name => $bundleFactory) {
    $drawTotal = 0.0;
    for ($trial = 0; $trial < POOL_TRIALS; $trial++) {
        $pool = BoxPool::of($boxItems, fn (array $item): int => $item['weight'], fn (array $item): int => $item['stock'], bundleFactory: $bundleFactory, randomizer: new SeededRandomizer($trial));
        $drawTotal += measureMs(function () use ($pool): void {
            while (! $pool->isEmpty()) { $pool->draw(); }
        });
    }
    printRow("Draw all ({$name})", $drawTotal, POOL_TRIALS * $boxDrawsPerPool);
}

// ===========================================================================
// Section 3: Selector pick throughput vs item count
// ===========================================================================

echo "\n" . str_repeat('=', 80) . "\n";
echo '  Selector pick throughput vs item count (' . SELECTOR_PICKS . " picks each)\n";
echo str_repeat('=', 80) . "\n";
printf("%-8s %20s %20s %20s\n", 'Items', 'PrefixSum O(log n)', 'Fenwick O(log n)', 'Alias O(1)');
echo str_repeat('-', 72) . "\n";

foreach ([3, 10, 30, 50, 100, 200, 500, 1000] as $n) {
    $weights = range(1, $n);
    $results = [];
    foreach ([
        'prefix'  => PrefixSumSelector::build($weights),
        'fenwick' => FenwickTreeSelector::build($weights),
        'alias'   => AliasTableSelector::build($weights),
    ] as $key => $sel) {
        $rng = new SeededRandomizer(42);
        $ms  = measureMs(function () use ($sel, $rng): void {
            for ($i = 0; $i < SELECTOR_PICKS; $i++) { $sel->pick($rng); }
        });
        $results[$key] = $ms / SELECTOR_PICKS * 1_000;
    }
    printf("%-8d %17.4f µs %17.4f µs %17.4f µs\n", $n, $results['prefix'], $results['fenwick'], $results['alias']);
}

echo str_repeat('-', 72) . "\n";

// ===========================================================================
// Section 4: Accuracy — max deviation from expected (equal weights)
// ===========================================================================

echo "\n" . str_repeat('=', 80) . "\n";
echo "  Accuracy: max absolute deviation from expected (equal weights, " . ACCURACY_DRAWS_PER_ITEM . " draws/item)\n";
echo str_repeat('=', 80) . "\n";
printf("%-8s %22s %22s %22s\n", 'N', 'PrefixSum (%)', 'Fenwick (%)', 'Alias (%)');
echo str_repeat('-', 78) . "\n";

foreach ([10, 50, 100, 500, 1000] as $n) {
    $weights = array_fill(0, $n, 1);
    $draws   = $n * ACCURACY_DRAWS_PER_ITEM;
    $exp     = 100.0 / $n;
    $row     = [];

    foreach ([
        'prefix'  => PrefixSumSelector::build($weights),
        'fenwick' => FenwickTreeSelector::build($weights),
        'alias'   => AliasTableSelector::build($weights),
    ] as $key => $sel) {
        $rng    = new SeededRandomizer(99);
        $counts = array_fill(0, $n, 0);
        for ($i = 0; $i < $draws; $i++) { $counts[$sel->pick($rng)]++; }
        $maxDev = 0.0;
        foreach ($counts as $c) { $maxDev = max($maxDev, abs($c / $draws * 100.0 - $exp)); }
        $row[$key] = $maxDev;
    }

    printf("%-8d %19.6f %% %19.6f %% %19.6f %%\n", $n, $row['prefix'], $row['fenwick'], $row['alias']);
}

echo str_repeat('-', 78) . "\n";
echo "\nDone.\n";
