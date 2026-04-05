<?php

declare(strict_types=1);

/**
 * Benchmark: build time, draw time, and total throughput for all pool types.
 *
 * Sections:
 *   1. WeightedPool  — immutable pool; build once, draw repeatedly
 *   2. DestructivePool — stateful; each trial rebuilds and draws until empty
 *   3. BoxPool       — stateful; each trial rebuilds and draws until stock runs out
 *   4. Selector pick throughput — PrefixSum / Alias / Fenwick across item counts
 *   5. DestructivePool scaling  — PrefixSum O(n²) vs FenwickTree O(n log n)
 *   6. Accuracy                 — max deviation from expected across all selectors
 */

require_once __DIR__ . '/../vendor/autoload.php';

use WeightedSample\Pool\BoxPool;
use WeightedSample\Pool\DestructivePool;
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\AliasTableSelector;
use WeightedSample\Selector\FenwickTreeSelector;
use WeightedSample\Selector\PrefixSumSelector;

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

foreach (['PrefixSum' => PrefixSumSelector::class, 'Alias' => AliasTableSelector::class] as $name => $cls) {
    $pool = WeightedPool::of($weightedItems, fn (array $item): int => $item['weight'], selectorClass: $cls, randomizer: new SeededRandomizer(42));
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
// Section 2: DestructivePool (small pool, 3 items)
// ===========================================================================

$destructiveN = count($destructiveItems);
printSectionHeader("DestructivePool (n={$destructiveN}: Gold/Silver/Bronze)");

$ms = measureMs(function () use ($destructiveItems): void {
    for ($i = 0; $i < BUILD_TRIALS; $i++) {
        DestructivePool::of($destructiveItems, fn (array $item): int => $item['weight'], randomizer: new SeededRandomizer(42));
    }
});
printRow('Build only', $ms, BUILD_TRIALS);

foreach (['PrefixSum' => PrefixSumSelector::class, 'Fenwick' => FenwickTreeSelector::class] as $name => $cls) {
    $drawTotal = 0.0;
    for ($trial = 0; $trial < POOL_TRIALS; $trial++) {
        $pool = DestructivePool::of($destructiveItems, fn (array $item): int => $item['weight'], selectorClass: $cls, randomizer: new SeededRandomizer($trial));
        $drawTotal += measureMs(function () use ($pool, $destructiveN): void {
            for ($d = 0; $d < $destructiveN; $d++) { $pool->draw(); }
        });
    }
    printRow("Draw all ({$name})", $drawTotal, POOL_TRIALS * $destructiveN);
}

$ms = measureMs(function () use ($destructiveItems): void {
    for ($trial = 0; $trial < POOL_TRIALS; $trial++) {
        $pool = DestructivePool::of($destructiveItems, fn (array $item): int => $item['weight'], randomizer: new SeededRandomizer($trial));
        while (! $pool->isEmpty()) { $pool->draw(); }
    }
});
printRow('Total (B+DrawAll)', $ms, POOL_TRIALS);

// ===========================================================================
// Section 3: BoxPool
// ===========================================================================

$boxDrawsPerPool = (int) array_sum(array_column($boxItems, 'stock'));
printSectionHeader("BoxPool (Gold×1, Silver×3, Bronze×6 = {$boxDrawsPerPool} draws/box)");

$ms = measureMs(function () use ($boxItems): void {
    for ($i = 0; $i < BUILD_TRIALS; $i++) {
        BoxPool::of($boxItems, fn (array $item): int => $item['weight'], fn (array $item): int => $item['stock'], randomizer: new SeededRandomizer(42));
    }
});
printRow('Build only', $ms, BUILD_TRIALS);

foreach (['PrefixSum' => PrefixSumSelector::class, 'Fenwick' => FenwickTreeSelector::class] as $name => $cls) {
    $drawTotal = 0.0;
    for ($trial = 0; $trial < POOL_TRIALS; $trial++) {
        $pool = BoxPool::of($boxItems, fn (array $item): int => $item['weight'], fn (array $item): int => $item['stock'], selectorClass: $cls, randomizer: new SeededRandomizer($trial));
        $drawTotal += measureMs(function () use ($pool): void {
            while (! $pool->isEmpty()) { $pool->draw(); }
        });
    }
    printRow("Draw all ({$name})", $drawTotal, POOL_TRIALS * $boxDrawsPerPool);
}

// ===========================================================================
// Section 4: Selector pick throughput vs item count
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
// Section 5: DestructivePool scaling — PrefixSum O(n²) vs FenwickTree O(n log n)
// ===========================================================================

echo "\n" . str_repeat('=', 80) . "\n";
echo "  DestructivePool scaling: draw-all time (µs/item) vs N\n";
echo "  PrefixSum = O(n²) total,  FenwickTree = O(n log n) total\n";
echo str_repeat('=', 80) . "\n";
printf("%-8s %22s %22s %10s\n", 'N', 'PrefixSum (µs/item)', 'Fenwick (µs/item)', 'Speedup');
echo str_repeat('-', 66) . "\n";

$scalingTrials = 200;
foreach ([10, 50, 100, 250, 500, 1000, 2500, 5000] as $n) {
    $items = array_map(fn (int $i): array => ['w' => $i], range(1, $n));

    $msPrefix = measureMs(function () use ($items, $n, $scalingTrials): void {
        for ($t = 0; $t < $scalingTrials; $t++) {
            $pool = DestructivePool::of($items, fn (array $item): int => $item['w'], selectorClass: PrefixSumSelector::class, randomizer: new SeededRandomizer($t));
            while (! $pool->isEmpty()) { $pool->draw(); }
        }
    });

    $msFenwick = measureMs(function () use ($items, $n, $scalingTrials): void {
        for ($t = 0; $t < $scalingTrials; $t++) {
            $pool = DestructivePool::of($items, fn (array $item): int => $item['w'], selectorClass: FenwickTreeSelector::class, randomizer: new SeededRandomizer($t));
            while (! $pool->isEmpty()) { $pool->draw(); }
        }
    });

    $totalDraws    = $scalingTrials * $n;
    $prefixUsItem  = $msPrefix  / $totalDraws * 1_000;
    $fenwickUsItem = $msFenwick / $totalDraws * 1_000;
    $speedup       = $msFenwick > 0 ? $msPrefix / $msFenwick : 0.0;

    printf("%-8d %19.4f µs %19.4f µs %9.2fx\n", $n, $prefixUsItem, $fenwickUsItem, $speedup);
}

echo str_repeat('-', 66) . "\n";
echo "Speedup > 1.0 means FenwickTree is faster.\n";

// ===========================================================================
// Section 6: Accuracy — max deviation from expected (equal weights)
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
