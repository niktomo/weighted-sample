<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use WeightedSample\Pool\BoxPool;
use WeightedSample\Pool\DestructivePool;
use WeightedSample\Pool\WeightedPool;

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
    fn ($item) => $item['weight'],
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

$equalPool   = WeightedPool::of($equalItems, fn ($item) => $item['weight']);
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
    $pool      = DestructivePool::of($destructiveItems, fn ($item) => $item['weight']);
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
        fn ($item) => $item['weight'],
        fn ($item) => $item['stock'],
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
// WeightedPool — 100 items (weight 1–100), 1,000,000 draws
// Verifies O(log n) binary search accuracy across a large item set
// ---------------------------------------------------------------------------

$largeItems = [];

for ($itemIndex = 1; $itemIndex <= 100; $itemIndex++) {
    $largeItems[] = ['name' => "w{$itemIndex}", 'weight' => $itemIndex];
}

// total weight = 1+2+...+100 = 5050
$largeTotalWeight = array_sum(array_column($largeItems, 'weight'));

$largePool   = WeightedPool::of($largeItems, fn ($item) => $item['weight']);
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

echo "\nDone. '!' marks deviation > 0.5 percentage points.\n";
