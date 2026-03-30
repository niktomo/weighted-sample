<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use WeightedSample\Pool\BoxPool;
use WeightedSample\Pool\DestructivePool;
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\AliasTableSelector;
use WeightedSample\Selector\PrefixSumSelector;

const DRAWS = 1_000_000;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param array<string, int> $counts
 * @param array<string, int> $expected  key => expected weight (not percentage)
 */
function printResult(string $title, array $counts, array $expected): void
{
    $total         = array_sum($counts);
    $expectedTotal = array_sum($expected);

    echo "\n=== {$title} ===\n";
    printf("%-12s %10s %10s %10s %8s\n", 'Item', 'Draws', 'Actual%', 'Expected%', 'Diff');
    echo str_repeat('-', 56) . "\n";

    foreach ($counts as $name => $drawCount) {
        $actualPct   = $drawCount / $total * 100;
        $expectedPct = $expected[$name] / $expectedTotal * 100;
        $diff        = $actualPct - $expectedPct;
        $marker      = abs($diff) > 0.5 ? ' !' : '';
        printf(
            "%-12s %10d %9.3f%% %9.3f%% %+8.3f%%%s\n",
            $name,
            $drawCount,
            $actualPct,
            $expectedPct,
            $diff,
            $marker,
        );
    }

    echo str_repeat('-', 56) . "\n";
    printf("%-12s %10d %9.3f%%\n", 'Total', $total, 100.0);
}

// ---------------------------------------------------------------------------
// WeightedPool — SSR/SR/R gacha
// ---------------------------------------------------------------------------

$weightedItems = [
    ['name' => 'SSR', 'weight' => 1],
    ['name' => 'SR',  'weight' => 9],
    ['name' => 'R',   'weight' => 90],
];

$weightedPool = WeightedPool::of(
    $weightedItems,
    fn (array $item) => $item['weight'],
);

$weightedCounts = ['SSR' => 0, 'SR' => 0, 'R' => 0];

for ($i = 0; $i < DRAWS; $i++) {
    $item = $weightedPool->draw();
    $weightedCounts[$item['name']]++;
}

printResult(
    'WeightedPool — 1,000,000 draws (SSR=1%, SR=9%, R=90%)',
    $weightedCounts,
    ['SSR' => 1, 'SR' => 9, 'R' => 90],
);

// ---------------------------------------------------------------------------
// WeightedPool — equal weights
// ---------------------------------------------------------------------------

$equalItems = [
    ['name' => 'A', 'weight' => 1],
    ['name' => 'B', 'weight' => 1],
    ['name' => 'C', 'weight' => 1],
    ['name' => 'D', 'weight' => 1],
];

$equalPool   = WeightedPool::of($equalItems, fn (array $item) => $item['weight']);
$equalCounts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];

for ($i = 0; $i < DRAWS; $i++) {
    $item = $equalPool->draw();
    $equalCounts[$item['name']]++;
}

printResult(
    'WeightedPool — 1,000,000 draws (equal weights, 25% each)',
    $equalCounts,
    ['A' => 1, 'B' => 1, 'C' => 1, 'D' => 1],
);

// ---------------------------------------------------------------------------
// DestructivePool — 3 items, draw all
// ---------------------------------------------------------------------------

$destructiveItems = [
    ['name' => 'Gold',   'weight' => 10],
    ['name' => 'Silver', 'weight' => 30],
    ['name' => 'Bronze', 'weight' => 60],
];

$destructiveCounts = ['Gold' => 0, 'Silver' => 0, 'Bronze' => 0];
$trials            = 100_000;

for ($trial = 0; $trial < $trials; $trial++) {
    $pool      = DestructivePool::of($destructiveItems, fn (array $item) => $item['weight']);
    $firstDraw = $pool->draw();
    $destructiveCounts[$firstDraw['name']]++;
}

printResult(
    "DestructivePool — {$trials} trials, first draw distribution (Gold=10%, Silver=30%, Bronze=60%)",
    $destructiveCounts,
    ['Gold' => 10, 'Silver' => 30, 'Bronze' => 60],
);

// ---------------------------------------------------------------------------
// BoxPool — box gacha (1 Gold, 3 Silver, 6 Bronze = 10 draws total)
// ---------------------------------------------------------------------------

$boxItems = [
    ['name' => 'Gold',   'weight' => 10, 'stock' => 1],
    ['name' => 'Silver', 'weight' => 30, 'stock' => 3],
    ['name' => 'Bronze', 'weight' => 60, 'stock' => 6],
];

$boxCounts = ['Gold' => 0, 'Silver' => 0, 'Bronze' => 0];
$boxTrials = 100_000;

for ($trial = 0; $trial < $boxTrials; $trial++) {
    $pool = BoxPool::of(
        $boxItems,
        fn (array $item) => $item['weight'],
        fn (array $item) => $item['stock'],
    );

    while (! $pool->isEmpty()) {
        $item = $pool->draw();
        $boxCounts[$item['name']]++;
    }
}

// Expected: Gold=1/10=10%, Silver=3/10=30%, Bronze=6/10=60%
printResult(
    "BoxPool — {$boxTrials} full box cycles (Gold=10%, Silver=30%, Bronze=60%)",
    $boxCounts,
    ['Gold' => 1, 'Silver' => 3, 'Bronze' => 6],
);

// ---------------------------------------------------------------------------
// Fractional probability accuracy — 1/3, 1/7, 1/5 etc.
// ---------------------------------------------------------------------------

$fractionalCases = [
    '1/3 each  [1,1,1]'       => ['weights' => [1, 1, 1],       'labels' => ['A(1/3)', 'B(1/3)', 'C(1/3)']],
    '1/7,2/7,4/7  [1,2,4]'   => ['weights' => [1, 2, 4],       'labels' => ['A(1/7)', 'B(2/7)', 'C(4/7)']],
    '1/5 each  [1,1,1,1,1]'  => ['weights' => [1, 1, 1, 1, 1], 'labels' => ['A', 'B', 'C', 'D', 'E']],
];

$fractionalDraws = 1_000_000;

echo "\n=== Fractional probability accuracy ({$fractionalDraws} draws, seed=42) ===\n";
printf("%-26s %20s %20s\n", '', 'PrefixSum (integer)', 'Alias (float internal)');
printf("%-26s %20s %20s\n", 'Case', 'Max deviation', 'Max deviation');
echo str_repeat('-', 68) . "\n";

foreach ($fractionalCases as $title => $case) {
    $weights     = $case['weights'];
    $labels      = $case['labels'];
    $totalWeight = array_sum($weights);
    $indices     = array_keys($labels);

    foreach (['prefix' => PrefixSumSelector::class, 'alias' => AliasTableSelector::class] as $name => $selectorClass) {
        $pool   = WeightedPool::of($indices, fn (int $index) => $weights[$index], randomizer: new SeededRandomizer(42), selectorClass: $selectorClass);
        $counts = array_fill(0, count($weights), 0);

        for ($i = 0; $i < $fractionalDraws; $i++) {
            $counts[$pool->draw()]++;
        }

        $maxDeviation = 0.0;

        foreach ($indices as $index) {
            $actualPct        = $counts[$index] / $fractionalDraws * 100;
            $expectedPct      = $weights[$index] / $totalWeight * 100;
            $maxDeviation     = max($maxDeviation, abs($actualPct - $expectedPct));
        }

        $$name = $maxDeviation;
    }

    printf("%-26s %19.4f%% %19.4f%%\n", $title, $prefix, $alias);
}

// ---------------------------------------------------------------------------
// WeightedPool — 100 items (weight 1–100), 1,000,000 draws
// Verifies O(log n) binary search accuracy across a large item set
// ---------------------------------------------------------------------------

$largeItems = [];

for ($itemIndex = 1; $itemIndex <= 100; $itemIndex++) {
    $largeItems[] = ['name' => "w{$itemIndex}", 'weight' => $itemIndex];
}

// total weight = 1+2+...+100 = 5050
$largeTotalWeight = array_sum(array_column($largeItems, 'weight'));

$largePool   = WeightedPool::of($largeItems, fn (array $item) => $item['weight']);
$largeCounts = array_fill_keys(array_column($largeItems, 'name'), 0);
$largeDraws  = 1_000_000;

for ($i = 0; $i < $largeDraws; $i++) {
    $item = $largePool->draw();
    $largeCounts[$item['name']]++;
}

echo "\n=== WeightedPool — 100 items (weight 1–100), 1,000,000 draws ===\n";
printf("%-8s %8s %10s %10s %8s\n", 'Item', 'Draws', 'Actual%', 'Expected%', 'Diff');
echo str_repeat('-', 50) . "\n";

$maxDiff = 0.0;

foreach ($largeItems as $largeItem) {
    $name        = $largeItem['name'];
    $drawCount   = $largeCounts[$name];
    $actualPct   = $drawCount / $largeDraws * 100;
    $expectedPct = $largeItem['weight'] / $largeTotalWeight * 100;
    $diff        = $actualPct - $expectedPct;
    $maxDiff     = max($maxDiff, abs($diff));
    $marker      = abs($diff) > 0.5 ? ' !' : '';
    printf(
        "%-8s %8d %9.3f%% %9.3f%% %+8.3f%%%s\n",
        $name,
        $drawCount,
        $actualPct,
        $expectedPct,
        $diff,
        $marker,
    );
}

echo str_repeat('-', 50) . "\n";
printf("%-8s %8d %9.3f%%\n", 'Total', $largeDraws, 100.0);
printf("Max deviation: %.4f%%\n", $maxDiff);

// ---------------------------------------------------------------------------
// Speed comparison: PrefixSumSelector vs AliasTableSelector
// 100 items (weight 1–100), 1,000,000 picks each
// ---------------------------------------------------------------------------

$speedWeights = range(1, 100);
$speedDraws   = 1_000_000;

echo "\n=== Speed comparison: PrefixSumSelector vs AliasTableSelector ({$speedDraws} picks) ===\n";
printf("%-12s %22s %22s %10s\n", 'Items', 'PrefixSum O(log n)', 'Alias O(1)', 'Ratio');
echo str_repeat('-', 70) . "\n";

foreach ([10, 50, 100, 200, 500, 1000] as $itemCount) {
    $weights = range(1, $itemCount);

    $prefixSelector = PrefixSumSelector::build($weights);
    $aliasSelector  = AliasTableSelector::build($weights);

    $prefixRandomizer = new SeededRandomizer(42);
    $prefixStart      = hrtime(true);
    for ($i = 0; $i < $speedDraws; $i++) {
        $prefixSelector->pick($prefixRandomizer);
    }
    $prefixMs = (hrtime(true) - $prefixStart) / 1_000_000;

    $aliasRandomizer = new SeededRandomizer(42);
    $aliasStart      = hrtime(true);
    for ($i = 0; $i < $speedDraws; $i++) {
        $aliasSelector->pick($aliasRandomizer);
    }
    $aliasMs = (hrtime(true) - $aliasStart) / 1_000_000;

    printf("%-12d %19.2f ms %19.2f ms %9.2fx\n", $itemCount, $prefixMs, $aliasMs, $aliasMs / $prefixMs);
}

echo "\nDone. '!' marks deviation > 0.5 percentage points.\n";
