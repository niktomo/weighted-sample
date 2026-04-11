<?php

declare(strict_types=1);

/**
 * Selector comparison — PrefixSum / Alias / Fenwick
 * Outputs benchmark/compare.html
 *
 * Part 1: WeightedPool (immutable) — build + pick throughput + accuracy
 *   Performance weights : range(1, N)  — varied, triggers Vose's loop realistically
 *   Accuracy weights    : array_fill(0, N, 1) — uniform, expected value = 1/N
 *   Heatmap: break-even draw count (PrefixSum vs Alias) per N
 *
 * Part 2: BoxPool scaling — RebuildBuilder O(n²) vs FenwickBuilder O(n log n)
 *   Total draw-all time as N grows — shows asymptotic advantage of FenwickTree
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
use WeightedSample\SelectorFactoryInterface;

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

/** N values for WeightedPool comparison */
const ITEM_COUNTS             = [2, 5, 10, 50, 100, 500, 1000, 5000, 10000, 50000];

const PICK_ITERATIONS         = 200_000;
const ACCURACY_DRAWS_PER_ITEM = 500;

/** Draw counts for heatmap rows */
const HEATMAP_DRAWS = [1, 2, 3, 5, 7, 10, 15, 20, 30, 50, 75, 100, 150, 200,
                        300, 500, 750, 1000, 2000, 5000, 10000, 50000, 100000];

/** N values for BoxPool scaling benchmark */
const BOX_N = [10, 50, 100, 250, 500, 1000, 2500, 5000];

function buildTrials(int $n): int
{
    return match (true) {
        $n <= 1000  => 300,
        $n <= 5000  => 100,
        $n <= 10000 => 50,
        default     => 15,
    };
}

function boxTrials(int $n): int
{
    return match (true) {
        $n <= 100  => 200,
        $n <= 500  => 50,
        $n <= 1000 => 20,
        $n <= 2500 => 5,
        default    => 2,
    };
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function measureMs(callable $callable): float
{
    $start = hrtime(true);
    $callable();
    return (hrtime(true) - $start) / 1_000_000;
}

/** @param list<int> $weights */
function buildPool(array $weights, SelectorFactoryInterface $selectorFactory): WeightedPool
{
    return WeightedPool::of(
        $weights,
        fn (int $w): int => $w,
        selectorFactory: $selectorFactory,
        randomizer: new SeededRandomizer(42),
    );
}

/** @param list<int> $weights */
function measureAccuracy(array $weights, SelectorFactoryInterface $selectorFactory): float
{
    $n      = count($weights);
    $draws  = $n * ACCURACY_DRAWS_PER_ITEM;
    $sel    = $selectorFactory->create($weights);
    $rng    = new SeededRandomizer(99);
    $counts = array_fill(0, $n, 0);
    for ($i = 0; $i < $draws; $i++) {
        $counts[$sel->pick($rng)]++;
    }
    $exp    = 100.0 / $n;
    $maxDev = 0.0;
    foreach ($counts as $c) {
        $maxDev = max($maxDev, abs($c / $draws * 100.0 - $exp));
    }
    return $maxDev;
}

// ---------------------------------------------------------------------------
// Warm-up: trigger JIT / opcode cache before measurements
// ---------------------------------------------------------------------------

$_warmWeights        = range(1, 100);
$_warmRng            = new SeededRandomizer(0);
$_prefixFactory      = new PrefixSumSelectorFactory();
$_aliasFactory       = new AliasTableSelectorFactory();
$_fenwickFactory     = new FenwickTreeSelectorFactory();
for ($_ = 0; $_ < 500; $_++) {
    buildPool($_warmWeights, $_prefixFactory);
    buildPool($_warmWeights, $_aliasFactory);
    $_prefixFactory->create($_warmWeights)->pick($_warmRng);
    $_fenwickFactory->create($_warmWeights)->pick($_warmRng);
    $_aliasFactory->create($_warmWeights)->pick($_warmRng);
}
unset($_warmWeights, $_warmRng, $_, $_prefixFactory, $_aliasFactory, $_fenwickFactory);

// ---------------------------------------------------------------------------
// Part 1: WeightedPool — build / pick / accuracy
// ---------------------------------------------------------------------------

echo "Part 1: WeightedPool (build / pick / accuracy)...\n";
printf("%-7s | %-11s | %-11s | %-11s | %-11s | %-11s | %-9s | %-9s | %-9s\n",
    'N', 'Build P', 'Build A', 'Pick P', 'Pick F', 'Pick A', 'Acc P%', 'Acc F%', 'Acc A%');
echo str_repeat('-', 100) . "\n";

/** @var array<int,float> */
$buildPrefix = [];
/** @var array<int,float> */
$buildAlias  = [];
/** @var array<int,float> */
$pickPrefix  = [];
/** @var array<int,float> */
$pickFenwick = [];
/** @var array<int,float> */
$pickAlias   = [];
/** @var array<int,float> */
$accPrefix   = [];
/** @var array<int,float> */
$accFenwick  = [];
/** @var array<int,float> */
$accAlias    = [];

$prefixFactory  = new PrefixSumSelectorFactory();
$aliasFactory   = new AliasTableSelectorFactory();
$fenwickFactory = new FenwickTreeSelectorFactory();

foreach (ITEM_COUNTS as $n) {
    $perfWeights    = range(1, $n);
    $uniformWeights = array_fill(0, $n, 1);
    $trials         = buildTrials($n);

    // Build (PrefixSum only — Alias build is charted separately, Fenwick ≈ PrefixSum)
    $ms = measureMs(function () use ($perfWeights, $trials, $prefixFactory): void {
        for ($i = 0; $i < $trials; $i++) { buildPool($perfWeights, $prefixFactory); }
    });
    $buildPrefix[$n] = round($ms / $trials * 1_000, 4);

    $ms = measureMs(function () use ($perfWeights, $trials, $aliasFactory): void {
        for ($i = 0; $i < $trials; $i++) { buildPool($perfWeights, $aliasFactory); }
    });
    $buildAlias[$n] = round($ms / $trials * 1_000, 4);

    // Pick — use selector instances directly for raw throughput
    $selPrefix  = $prefixFactory->create($perfWeights);
    $selFenwick = $fenwickFactory->create($perfWeights);
    $selAlias   = $aliasFactory->create($perfWeights);

    // Seed 42: arbitrary fixed value for reproducibility across runs. NOT for production use.
    $rng = new SeededRandomizer(42);
    $ms  = measureMs(function () use ($selPrefix, $rng): void {
        for ($i = 0; $i < PICK_ITERATIONS; $i++) { $selPrefix->pick($rng); }
    });
    $pickPrefix[$n] = round($ms / PICK_ITERATIONS * 1_000, 4);

    $rng = new SeededRandomizer(42);
    $ms  = measureMs(function () use ($selFenwick, $rng): void {
        for ($i = 0; $i < PICK_ITERATIONS; $i++) { $selFenwick->pick($rng); }
    });
    $pickFenwick[$n] = round($ms / PICK_ITERATIONS * 1_000, 4);

    $rng = new SeededRandomizer(42);
    $ms  = measureMs(function () use ($selAlias, $rng): void {
        for ($i = 0; $i < PICK_ITERATIONS; $i++) { $selAlias->pick($rng); }
    });
    $pickAlias[$n] = round($ms / PICK_ITERATIONS * 1_000, 4);

    // Accuracy: seed 99 (differs from pick seed 42 to avoid accidental correlation).
    // Uniform weights — expected pick rate = 1/N for every index.
    $accPrefix[$n]  = round(measureAccuracy($uniformWeights, $prefixFactory), 6);
    $accFenwick[$n] = round(measureAccuracy($uniformWeights, $fenwickFactory), 6);
    $accAlias[$n]   = round(measureAccuracy($uniformWeights, $aliasFactory), 6);

    printf("N=%-5d | %8.4fµs | %8.4fµs | %8.4fµs | %8.4fµs | %8.4fµs | %7.4f | %7.4f | %7.4f\n",
        $n,
        $buildPrefix[$n], $buildAlias[$n],
        $pickPrefix[$n], $pickFenwick[$n], $pickAlias[$n],
        $accPrefix[$n], $accFenwick[$n], $accAlias[$n],
    );
}

// ---------------------------------------------------------------------------
// Part 2: BoxPool scaling
// ---------------------------------------------------------------------------

echo "\nPart 2: BoxPool scaling (draw-all time µs/item)...\n";
printf("%-7s | %-20s | %-20s | %-10s\n", 'N', 'RebuildBuilder µs/item', 'FenwickBuilder µs/item', 'Speedup');
echo str_repeat('-', 65) . "\n";

/** @var array<int,float> */
$boxRebuild = [];
/** @var array<int,float> */
$boxFenwick = [];

$rebuildBundleFactory = new RebuildSelectorBundleFactory($prefixFactory);
$fenwickBundleFactory = new FenwickSelectorBundleFactory();

foreach (BOX_N as $n) {
    $items  = range(1, $n);
    $trials = boxTrials($n);

    $msR = measureMs(function () use ($items, $trials, $rebuildBundleFactory): void {
        for ($t = 0; $t < $trials; $t++) {
            $pool = BoxPool::of(
                $items,
                fn (int $w): int => $w,
                fn (int $w): int => 1,
                bundleFactory: $rebuildBundleFactory,
                randomizer: new SeededRandomizer($t),
            );
            while (! $pool->isEmpty()) { $pool->draw(); }
        }
    });

    $msF = measureMs(function () use ($items, $trials, $fenwickBundleFactory): void {
        for ($t = 0; $t < $trials; $t++) {
            $pool = BoxPool::of(
                $items,
                fn (int $w): int => $w,
                fn (int $w): int => 1,
                bundleFactory: $fenwickBundleFactory,
                randomizer: new SeededRandomizer($t),
            );
            while (! $pool->isEmpty()) { $pool->draw(); }
        }
    });

    $totalDraws       = $trials * $n;
    $boxRebuild[$n]   = round($msR / $totalDraws * 1_000, 4);
    $boxFenwick[$n]   = round($msF / $totalDraws * 1_000, 4);
    $speedup          = $msF > 0 ? round($msR / $msF, 2) : 0.0;

    printf("N=%-5d | %17.4f µs | %17.4f µs | %8.2fx\n",
        $n, $boxRebuild[$n], $boxFenwick[$n], $speedup);
}

// ---------------------------------------------------------------------------
// Heatmap (PrefixSum vs Alias — WeightedPool context)
// ---------------------------------------------------------------------------

/** @var array<int,float> */
$breakEven = [];
foreach (ITEM_COUNTS as $n) {
    $dp = $pickPrefix[$n] - $pickAlias[$n];
    $breakEven[$n] = $dp > 0 ? ($buildAlias[$n] - $buildPrefix[$n]) / $dp : INF;
}

$heatmap = [];
foreach (HEATMAP_DRAWS as $d) {
    $row = [];
    foreach (ITEM_COUNTS as $n) {
        $tp      = $buildPrefix[$n] + $d * $pickPrefix[$n];
        $ta      = $buildAlias[$n]  + $d * $pickAlias[$n];
        $row[$n] = round($ta / $tp, 4);
    }
    $heatmap[$d] = $row;
}

// ---------------------------------------------------------------------------
// Build HTML tables
// ---------------------------------------------------------------------------

function ratioToStyle(float $ratio): string
{
    if ($ratio < 1.0) {
        // Alias wins: green tint. intensity ∈ [0,1] scaled so ratio=0.75 → intensity=1.0 (4× factor).
        $intensity = min(1.0, (1.0 - $ratio) * 4);
        $g  = (int)(80 + 100 * $intensity); // green channel: 80 (weak) → 180 (strong)
        $bg = "rgba(16,{$g},81," . round(0.15 + 0.5 * $intensity, 2) . ')'; // Tailwind emerald palette base
        $fg = $intensity > 0.4 ? '#6ee7b7' : '#a7f3d0';                      // brighter text at high intensity
        return "background:{$bg};color:{$fg}";
    }
    // PrefixSum wins: blue tint. intensity ∈ [0,1] scaled so ratio=1.25 → intensity=1.0.
    $intensity = min(1.0, ($ratio - 1.0) * 4);
    $b  = (int)(100 + 155 * $intensity); // blue channel: 100 (weak) → 255 (strong)
    $bg = "rgba(30,58,{$b}," . round(0.15 + 0.5 * $intensity, 2) . ')'; // Tailwind blue palette base
    $fg = $intensity > 0.4 ? '#93c5fd' : '#bfdbfe';                      // brighter text at high intensity
    return "background:{$bg};color:{$fg}";
}

$heatmapHtml = '<table class="hmap"><thead><tr><th>draws ↓ / N →</th>';
foreach (ITEM_COUNTS as $n) { $heatmapHtml .= "<th>{$n}</th>"; }
$heatmapHtml .= '</tr></thead><tbody>';
foreach (HEATMAP_DRAWS as $d) {
    $heatmapHtml .= "<tr><td class='draws-label'>{$d}</td>";
    foreach (ITEM_COUNTS as $n) {
        $ratio  = $heatmap[$d][$n];
        $style  = ratioToStyle($ratio);
        $be     = $breakEven[$n];
        $prevD  = HEATMAP_DRAWS[array_search($d, HEATMAP_DRAWS) - 1] ?? 0;
        $border = ($be >= $prevD && $be < $d) ? 'border-top:2px solid #fbbf24;' : '';
        $label  = $ratio < 1.0 ? 'A' : 'P';
        $heatmapHtml .= "<td style='{$style}{$border}' title='ratio={$ratio}'>{$label} {$ratio}</td>";
    }
    $heatmapHtml .= '</tr>';
}
$heatmapHtml .= '</tbody></table>';

$breakEvenHtml = '<table class="hmap"><thead><tr><th>N</th>';
foreach (ITEM_COUNTS as $n) { $breakEvenHtml .= "<th>{$n}</th>"; }
$breakEvenHtml .= '</tr></thead><tbody><tr><td class="draws-label">Break-even draws</td>';
foreach (ITEM_COUNTS as $n) {
    $be = $breakEven[$n];
    $v  = is_infinite($be) ? '∞' : (int) ceil($be) . ' draws';
    $breakEvenHtml .= "<td style='background:#1e293b;color:#fbbf24;font-weight:600;text-align:center'>{$v}</td>";
}
$breakEvenHtml .= '</tr></tbody></table>';

// BoxPool scaling table
$boxTableHtml = '<table class="hmap"><thead><tr><th>N</th>';
foreach (BOX_N as $n) { $boxTableHtml .= "<th>{$n}</th>"; }
$boxTableHtml .= '</tr></thead><tbody>';
$boxTableHtml .= '<tr><td class="draws-label">RebuildBuilder µs/item</td>';
foreach (BOX_N as $n) {
    $boxTableHtml .= "<td style='background:#1e3a5f;color:#93c5fd;text-align:center'>{$boxRebuild[$n]}</td>";
}
$boxTableHtml .= '</tr><tr><td class="draws-label">FenwickBuilder µs/item</td>';
foreach (BOX_N as $n) {
    $boxTableHtml .= "<td style='background:#064e3b;color:#6ee7b7;text-align:center'>{$boxFenwick[$n]}</td>";
}
$boxTableHtml .= '</tr><tr><td class="draws-label">Speedup (R/F)</td>';
foreach (BOX_N as $n) {
    $speedup = $boxFenwick[$n] > 0 ? round($boxRebuild[$n] / $boxFenwick[$n], 1) : 0.0;
    $color   = $speedup >= 2.0 ? '#fbbf24' : '#94a3b8';
    $boxTableHtml .= "<td style='background:#1e293b;color:{$color};font-weight:600;text-align:center'>{$speedup}x</td>";
}
$boxTableHtml .= '</tr></tbody></table>';

// ---------------------------------------------------------------------------
// JSON for Chart.js
// ---------------------------------------------------------------------------

// JSON_THROW_ON_ERROR ensures encoding failures surface immediately rather than silently producing null.
$labelsJson        = json_encode(ITEM_COUNTS,               JSON_THROW_ON_ERROR);
$boxLabelsJson     = json_encode(BOX_N,                     JSON_THROW_ON_ERROR);
$buildPrefixJson   = json_encode(array_values($buildPrefix), JSON_THROW_ON_ERROR);
$buildAliasJson    = json_encode(array_values($buildAlias),  JSON_THROW_ON_ERROR);
$pickPrefixJson    = json_encode(array_values($pickPrefix),  JSON_THROW_ON_ERROR);
$pickFenwickJson   = json_encode(array_values($pickFenwick), JSON_THROW_ON_ERROR);
$pickAliasJson     = json_encode(array_values($pickAlias),   JSON_THROW_ON_ERROR);
$accPrefixJson     = json_encode(array_values($accPrefix),   JSON_THROW_ON_ERROR);
$accFenwickJson    = json_encode(array_values($accFenwick),  JSON_THROW_ON_ERROR);
$accAliasJson      = json_encode(array_values($accAlias),    JSON_THROW_ON_ERROR);
// 3σ upper bound: approximate single-test threshold. Multiple selectors (×3) mean the true
// family-wise bound is slightly higher (Bonferroni: ×√3 ≈ ×1.73), but for visual guidance this is sufficient.
$expectedMaxDevJson = json_encode(array_map(
    fn (int $n) => round(3 * sqrt(1.0 / ($n * ACCURACY_DRAWS_PER_ITEM)) * 100, 6),
    ITEM_COUNTS,
),                                                           JSON_THROW_ON_ERROR);
$boxRebuildJson    = json_encode(array_values($boxRebuild),  JSON_THROW_ON_ERROR);
$boxFenwickJson    = json_encode(array_values($boxFenwick),  JSON_THROW_ON_ERROR);

$accDrawsPerItem = ACCURACY_DRAWS_PER_ITEM;

// ---------------------------------------------------------------------------
// HTML
// ---------------------------------------------------------------------------

$html = <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>Selector Comparison — weighted-sample</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; }
h1  { font-size: 1.35rem; font-weight: 700; color: #f8fafc; }
h2  { font-size: .95rem;  font-weight: 600; color: #94a3b8; margin-bottom: .45rem; }
h3  { font-size: .85rem;  font-weight: 600; color: #60a5fa; margin: 1rem 0 .4rem; }
.header { padding: 1.4rem 2rem .9rem; border-bottom: 1px solid #1e293b; }
.header p { margin-top: .4rem; color: #64748b; font-size: .8rem; line-height: 1.6; }
.section { padding: .9rem 2rem; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.1rem; }
.card { background: #1e293b; border: 1px solid #334155; border-radius: .7rem; padding: 1rem; }
.card.wide { grid-column: 1 / -1; }
canvas { max-height: 240px; }
.note { color: #64748b; font-size: .72rem; margin-top: .35rem; line-height: 1.5; }
.hmap { width: 100%; border-collapse: collapse; font-size: .75rem; }
.hmap th { background: #0f172a; color: #64748b; padding: .3rem .5rem; text-align: center;
           border: 1px solid #1e293b; font-weight: 500; white-space: nowrap; }
.hmap td { padding: .25rem .45rem; border: 1px solid #0f172a; text-align: center; white-space: nowrap; }
.draws-label { background: #1e293b !important; color: #94a3b8 !important; font-weight: 500;
               text-align: right !important; padding-right: .7rem !important; }
.verdict { background: #0f2744; border: 1px solid #1d4ed8; border-radius: .7rem; padding: 1rem; margin-bottom: 1.2rem; }
.verdict h2 { color: #60a5fa; margin-bottom: .55rem; }
.gtable { width: 100%; border-collapse: collapse; font-size: .8rem; }
.gtable th { text-align: left; padding: .3rem .65rem; color: #94a3b8; border-bottom: 1px solid #1e40af; font-weight: 500; }
.gtable td { padding: .3rem .65rem; border-bottom: 1px solid #1e293b; }
.a { background:#064e3b; color:#6ee7b7; border-radius:.2rem; padding:.1rem .35rem; font-size:.7rem; font-weight:600; }
.p { background:#1e3a5f; color:#93c5fd; border-radius:.2rem; padding:.1rem .35rem; font-size:.7rem; font-weight:600; }
.f { background:#431407; color:#fdba74; border-radius:.2rem; padding:.1rem .35rem; font-size:.7rem; font-weight:600; }
.divider { border: none; border-top: 2px solid #334155; margin: .5rem 0 1rem; }
</style>
</head>
<body>

<div class="header">
  <h1>Selector Comparison — PrefixSum / Fenwick / Alias</h1>
  <p>
    <b>Part 1</b>: WeightedPool（immutable）— build / pick 速度 + 精度。重み: range(1,N)、精度: equal weights × {$accDrawsPerItem} draws/item<br>
    <b>Part 2</b>: BoxPool scaling — RebuildBuilder O(n²) vs FenwickBuilder O(n log n)（n アイテムを全部引き切るまでの合計時間）
  </p>
</div>

<!-- ===== Part 1: 償却説明 ===== -->
<div class="section">
  <div style="background:#0f2744;border:1px solid #1d4ed8;border-radius:.7rem;padding:1rem;margin-bottom:1.2rem">
    <h2 style="color:#60a5fa;margin-bottom:.55rem">なぜ build が遅い Alias が最終的に速くなるのか</h2>
    <p style="font-size:.82rem;line-height:1.8;color:#cbd5e1">
      <b>build は 1 回だけ</b>、<b>draw は何度でも</b> 呼ばれます。<br>
      合計コスト = <b>Build × 1回</b> + <b>Pick × D回</b><br>
      draw 回数 D が増えると pick の速度差が積み上がり、やがてビルドコスト差を上回ります（損益分岐点）。<br>
      <code>D_be = (Build_alias − Build_prefix) / (Pick_prefix − Pick_alias)</code>
    </p>
  </div>
  <div style="background:#1a0a2e;border:1px solid #6d28d9;border-radius:.7rem;padding:.85rem;margin-bottom:1rem">
    <h2 style="color:#c4b5fd;margin-bottom:.4rem">なぜ損益分岐点は PrefixSum vs Alias の比較だけなのか</h2>
    <p style="font-size:.82rem;line-height:1.8;color:#ddd6fe">
      <b>FenwickTree は WeightedPool では常に PrefixSum より遅い</b>ため、選択肢に入りません。<br>
      FenwickTree の強みは <code>update()</code> による O(log n) 点更新ですが、WeightedPool（immutable）では draw のたびにセレクタを更新しません。<br>
      結果として pick のオーバーヘッドだけが残り、PrefixSum より常に遅くなります（上の Pick chart 参照）。<br>
      <b>FenwickTree が有効なのは BoxPool（mutable pool）のみ</b>です — Part 2 参照。
    </p>
  </div>
  <h3>損益分岐点（WeightedPool — PrefixSum vs Alias）</h3>
  {$breakEvenHtml}
</div>

<div class="section">
  <h3 style="color:#94a3b8;margin-bottom:.6rem">ヒートマップ — draw 数 × N でどちらが有利か（Alias / PrefixSum 合計時間比）</h3>
  {$heatmapHtml}
  <p class="note" style="margin-top:.5rem">各セル = Alias 合計 ÷ PrefixSum 合計。緑(A)= Alias が速い、青(P)= PrefixSum が速い。黄色ボーダー = 損益分岐点。</p>
</div>

<div class="section">
  <div class="grid">
    <div class="card">
      <h2>Build time (µs) vs N — WeightedPool</h2>
      <canvas id="buildChart"></canvas>
      <p class="note">FenwickTree の build は PrefixSum とほぼ同等。</p>
    </div>
    <div class="card">
      <h2>Pick time (µs/op) vs N — WeightedPool</h2>
      <canvas id="pickChart"></canvas>
      <p class="note">Alias O(1) は N によらず一定。PrefixSum / Fenwick は O(log n) で緩やかに増加。</p>
    </div>
    <div class="card wide">
      <h2>Accuracy — max deviation (%) from expected — 低いほど正確</h2>
      <canvas id="accChart"></canvas>
      <p class="note">グレー破線 = 3σ 理論上限。3 セレクタともこの範囲内 → 系統誤差なし。整数演算により float バイアス排除。</p>
    </div>
  </div>
</div>

<hr class="divider">

<!-- ===== Part 2: BoxPool scaling ===== -->
<div class="section">
  <div style="background:#1c0a00;border:1px solid #92400e;border-radius:.7rem;padding:1rem;margin-bottom:1.2rem">
    <h2 style="color:#fdba74;margin-bottom:.55rem">FenwickBuilder の真価 — BoxPool スケーリング</h2>
    <p style="font-size:.82rem;line-height:1.8;color:#fde68a">
      RebuildBuilder は draw のたびにセレクタを <b>全再構築 O(n)</b> → 全アイテムを引き切るまで <b>O(n²) 合計</b>。<br>
      FenwickBuilder は draw のたびに <b>点更新 O(log n)</b> → 全アイテムを引き切るまで <b>O(n log n) 合計</b>。<br>
      N が大きくなるほど差が開きます。
    </p>
  </div>
  <h3>BoxPool — draw-all time (µs/item) vs N</h3>
  {$boxTableHtml}
  <p class="note" style="margin-top:.5rem">
    µs/item = 全アイテムを引き切るまでの合計時間 ÷ N。RebuildBuilder は N とともに増加（O(n)）、FenwickBuilder はほぼ一定（O(log n)）。
  </p>
</div>

<div class="section">
  <div class="grid">
    <div class="card wide">
      <h2>BoxPool draw-all — µs/item vs N（片対数グラフ）</h2>
      <canvas id="boxChart"></canvas>
      <p class="note">縦軸を対数スケールにすると成長率の違いが明確に見える。RebuildBuilder は N に比例、FenwickBuilder はほぼ横ばい。</p>
    </div>
  </div>
</div>

<!-- ===== 選択ガイド ===== -->
<div class="section">
  <div class="verdict">
    <h2>選択ガイド</h2>
    <table class="gtable">
      <tr><th>ユースケース</th><th>推奨</th><th>理由</th></tr>
      <tr>
        <td>WeightedPool — 大量 draw（損益分岐点以上）</td>
        <td><span class="a">Alias</span></td>
        <td>O(1) pick。build コストは draw 数で償却される</td>
      </tr>
      <tr>
        <td>WeightedPool — 少数 draw（損益分岐点以下）</td>
        <td><span class="p">PrefixSum</span></td>
        <td>build が速く pick も十分高速。典型ガチャはこちら</td>
      </tr>
      <tr>
        <td>BoxPool — N ≦ 100 程度</td>
        <td><span class="p">PrefixSum</span></td>
        <td>小規模では再構築コストが小さく FenwickBuilder のオーバーヘッドが勝る</td>
      </tr>
      <tr>
        <td>BoxPool — N ≧ 500</td>
        <td><span class="f">Fenwick</span></td>
        <td>全引き O(n log n) vs O(n²)。N=1000 で大幅な差が生まれる</td>
      </tr>
      <tr>
        <td>精度最優先</td>
        <td>どれでも可</td>
        <td>3 セレクタとも整数演算・系統誤差なし。差は純粋なサンプリングノイズ</td>
      </tr>
    </table>
  </div>
</div>

<script>
const labels      = {$labelsJson};
const dLabels     = {$boxLabelsJson};
const BASE = {
  responsive: true,
  plugins: { legend: { labels: { color:'#94a3b8', boxWidth:13, font:{size:10} } } },
  scales: {
    x: { ticks:{color:'#64748b',font:{size:9}}, grid:{color:'#1e293b'},
         title:{display:true,text:'Item count (N)',color:'#64748b',font:{size:9}} },
    y: { ticks:{color:'#64748b',font:{size:9}}, grid:{color:'#1e293b'},
         title:{display:true,color:'#64748b',font:{size:9}} },
  },
};
const opt  = (t) => ({...BASE, scales:{...BASE.scales, y:{...BASE.scales.y, title:{display:true,text:t,color:'#64748b',font:{size:9}}}}});
const optLog = (t) => ({...opt(t), scales:{...opt(t).scales, y:{...opt(t).scales.y, type:'logarithmic'}}});

new Chart(document.getElementById('buildChart'), { type:'line', data:{ labels, datasets:[
  {label:'PrefixSum', data:{$buildPrefixJson}, borderColor:'#3b82f6', backgroundColor:'#3b82f622', tension:.3},
  {label:'Alias',     data:{$buildAliasJson},  borderColor:'#10b981', backgroundColor:'#10b98122', tension:.3},
]}, options: opt('µs / build') });

new Chart(document.getElementById('pickChart'), { type:'line', data:{ labels, datasets:[
  {label:'PrefixSum O(log n)', data:{$pickPrefixJson},  borderColor:'#3b82f6', backgroundColor:'#3b82f622', tension:.3},
  {label:'Fenwick O(log n)',   data:{$pickFenwickJson},  borderColor:'#f97316', backgroundColor:'#f9731622', tension:.3},
  {label:'Alias O(1)',         data:{$pickAliasJson},    borderColor:'#10b981', backgroundColor:'#10b98122', tension:.3},
]}, options: opt('µs / pick') });

new Chart(document.getElementById('accChart'), { type:'line', data:{ labels, datasets:[
  {label:'PrefixSum',      data:{$accPrefixJson},      borderColor:'#3b82f6', backgroundColor:'#3b82f622', tension:.3},
  {label:'Fenwick',        data:{$accFenwickJson},      borderColor:'#f97316', backgroundColor:'#f9731622', tension:.3},
  {label:'Alias',          data:{$accAliasJson},        borderColor:'#10b981', backgroundColor:'#10b98122', tension:.3},
  {label:'3σ upper bound', data:{$expectedMaxDevJson},  borderColor:'#475569', backgroundColor:'transparent', borderDash:[4,4], tension:.3, pointRadius:0},
]}, options: opt('max deviation (%)') });

new Chart(document.getElementById('boxChart'), { type:'line', data:{ labels: dLabels, datasets:[
  {label:'RebuildBuilder O(n²)',    data:{$boxRebuildJson}, borderColor:'#3b82f6', backgroundColor:'#3b82f622', tension:.3},
  {label:'FenwickBuilder O(n logn)', data:{$boxFenwickJson}, borderColor:'#f97316', backgroundColor:'#f9731622', tension:.3},
]}, options: optLog('µs / item (log scale)') });
</script>
</body>
</html>
HTML;

$outPath = __DIR__ . '/compare.html';
if (file_put_contents($outPath, $html) === false) {
    throw new \RuntimeException("Failed to write {$outPath} — check directory permissions.");
}
echo "\nDone → benchmark/compare.html\n";
