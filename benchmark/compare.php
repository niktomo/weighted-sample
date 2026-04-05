<?php

declare(strict_types=1);

/**
 * Selector comparison: PrefixSumSelector vs AliasTableSelector
 * Outputs benchmark/compare.html
 *
 * Performance weights : range(1, N)  — varied, triggers Vose's loop realistically
 * Accuracy weights    : array_fill(1, N)  — uniform, expected value = 1/N
 *
 * Heatmap break-even is computed analytically from measured B (build µs) and P (pick µs/op):
 *   total(D) = B + D × P
 *   break-even D = (B_alias − B_prefix) / (P_prefix − P_alias)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use WeightedSample\Pool\WeightedPool;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\AliasTableSelector;
use WeightedSample\Selector\PrefixSumSelector;

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const ITEM_COUNTS           = [2, 5, 10, 25, 50, 75, 100, 1000, 2500, 5000, 7500, 10000, 25000, 50000];
const PICK_ITERATIONS       = 200_000;
const ACCURACY_DRAWS_PER_ITEM = 500;

/** Draw counts used for heatmap rows (log scale). */
const HEATMAP_DRAWS = [1, 2, 3, 5, 7, 10, 15, 20, 30, 50, 75, 100, 150, 200,
                        300, 500, 750, 1000, 2000, 5000, 10000, 50000, 100000];

function buildTrials(int $n): int
{
    return match (true) {
        $n <= 1000  => 300,
        $n <= 5000  => 100,
        $n <= 10000 => 50,
        default     => 15,
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
function buildPool(array $weights, string $selectorClass): WeightedPool
{
    return WeightedPool::of(
        $weights,
        fn (int $w): int => $w,
        selectorClass: $selectorClass,
        randomizer: new SeededRandomizer(42),
    );
}

/** @param list<int> $weights  must be equal weights for expected value = 1/N */
function measureAccuracy(array $weights, string $selectorClass): float
{
    $n      = count($weights);
    $draws  = $n * ACCURACY_DRAWS_PER_ITEM;
    $sel    = $selectorClass::build($weights);
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
// Measure
// ---------------------------------------------------------------------------

echo "Running benchmark (varied weights = range(1,N), may take ~2 min)...\n\n";
printf("%-7s | %-13s | %-13s | %-13s | %-13s | %-11s | %-11s\n",
    'N', 'Build P µs', 'Build A µs', 'Pick P µs', 'Pick A µs', 'Acc P %', 'Acc A %');
echo str_repeat('-', 92) . "\n";

/** @var array<int,float> $buildPrefix */
$buildPrefix = [];
/** @var array<int,float> $buildAlias */
$buildAlias  = [];
/** @var array<int,float> $pickPrefix */
$pickPrefix  = [];
/** @var array<int,float> $pickAlias */
$pickAlias   = [];
/** @var array<int,float> $accPrefix */
$accPrefix   = [];
/** @var array<int,float> $accAlias */
$accAlias    = [];

foreach (ITEM_COUNTS as $n) {
    $perfWeights     = range(1, $n);   // varied: triggers Vose's loop
    $uniformWeights  = array_fill(0, $n, 1);
    $trials          = buildTrials($n);

    // Build
    $ms = measureMs(function () use ($perfWeights, $trials): void {
        for ($i = 0; $i < $trials; $i++) { buildPool($perfWeights, PrefixSumSelector::class); }
    });
    $buildPrefix[$n] = round($ms / $trials * 1_000, 4);

    $ms = measureMs(function () use ($perfWeights, $trials): void {
        for ($i = 0; $i < $trials; $i++) { buildPool($perfWeights, AliasTableSelector::class); }
    });
    $buildAlias[$n] = round($ms / $trials * 1_000, 4);

    // Pick
    $pSel = PrefixSumSelector::build($perfWeights);
    $pRng = new SeededRandomizer(42);
    $ms   = measureMs(function () use ($pSel, $pRng): void {
        for ($i = 0; $i < PICK_ITERATIONS; $i++) { $pSel->pick($pRng); }
    });
    $pickPrefix[$n] = round($ms / PICK_ITERATIONS * 1_000, 4);

    $aSel = AliasTableSelector::build($perfWeights);
    $aRng = new SeededRandomizer(42);
    $ms   = measureMs(function () use ($aSel, $aRng): void {
        for ($i = 0; $i < PICK_ITERATIONS; $i++) { $aSel->pick($aRng); }
    });
    $pickAlias[$n] = round($ms / PICK_ITERATIONS * 1_000, 4);

    // Accuracy (equal weights)
    $accPrefix[$n] = round(measureAccuracy($uniformWeights, PrefixSumSelector::class), 6);
    $accAlias[$n]  = round(measureAccuracy($uniformWeights, AliasTableSelector::class), 6);

    printf("N=%-5d | %13.4f | %13.4f | %13.4f | %13.4f | %11.6f | %11.6f\n",
        $n,
        $buildPrefix[$n], $buildAlias[$n],
        $pickPrefix[$n],  $pickAlias[$n],
        $accPrefix[$n],   $accAlias[$n],
    );
}

// ---------------------------------------------------------------------------
// Heatmap: analytical computation
//   total_prefix(D) = B_p + D * P_p
//   total_alias(D)  = B_a + D * P_a
//   ratio(N, D)     = total_alias / total_prefix
//   break-even D    = (B_a - B_p) / (P_p - P_a)   [only when P_p > P_a]
// ---------------------------------------------------------------------------

/** @var array<int,float> $breakEven  [N => D_breakeven] */
$breakEven = [];
foreach (ITEM_COUNTS as $n) {
    $dp = $pickPrefix[$n] - $pickAlias[$n];
    $breakEven[$n] = $dp > 0
        ? ($buildAlias[$n] - $buildPrefix[$n]) / $dp
        : INF;
}

// Heatmap ratio matrix: $heatmap[draws][n] = ratio
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
// Build heatmap HTML (PHP-generated table)
// ---------------------------------------------------------------------------

function ratioToStyle(float $ratio): string
{
    if ($ratio < 1.0) {
        // Alias wins — green gradient
        $intensity = min(1.0, (1.0 - $ratio) * 4);   // 0.75→full
        $g  = (int)(80  + 100 * $intensity);
        $bg = "rgba(16,{$g},81," . round(0.15 + 0.5 * $intensity, 2) . ')';
        $fg = $intensity > 0.4 ? '#6ee7b7' : '#a7f3d0';
        return "background:{$bg};color:{$fg}";
    } else {
        // PrefixSum wins — blue gradient
        $intensity = min(1.0, ($ratio - 1.0) * 4);
        $b  = (int)(100 + 155 * $intensity);
        $bg = "rgba(30,58,{$b}," . round(0.15 + 0.5 * $intensity, 2) . ')';
        $fg = $intensity > 0.4 ? '#93c5fd' : '#bfdbfe';
        return "background:{$bg};color:{$fg}";
    }
}

$heatmapHtml = '<table class="hmap">';
// Header
$heatmapHtml .= '<thead><tr><th>draws ↓ / N →</th>';
foreach (ITEM_COUNTS as $n) {
    $heatmapHtml .= "<th>{$n}</th>";
}
$heatmapHtml .= '</tr></thead><tbody>';

foreach (HEATMAP_DRAWS as $d) {
    $heatmapHtml .= "<tr><td class='draws-label'>{$d}</td>";
    foreach (ITEM_COUNTS as $n) {
        $ratio = $heatmap[$d][$n];
        $style = ratioToStyle($ratio);

        // Check if break-even is within this row
        $be    = $breakEven[$n];
        $prevD = HEATMAP_DRAWS[array_search($d, HEATMAP_DRAWS) - 1] ?? 0;
        $isBe  = ($be >= $prevD && $be < $d);

        $border    = $isBe ? 'border-top:2px solid #fbbf24;' : '';
        $label     = $ratio < 1.0 ? 'A' : 'P';
        $heatmapHtml .= "<td style='{$style}{$border}' title='ratio={$ratio}'>{$label} {$ratio}</td>";
    }
    $heatmapHtml .= '</tr>';
}
$heatmapHtml .= '</tbody></table>';

// Break-even summary row
$breakEvenHtml = '<table class="hmap"><thead><tr><th>N</th>';
foreach (ITEM_COUNTS as $n) {
    $breakEvenHtml .= "<th>{$n}</th>";
}
$breakEvenHtml .= '</tr></thead><tbody><tr><td class="draws-label">Break-even draws</td>';
foreach (ITEM_COUNTS as $n) {
    $be = $breakEven[$n];
    $v  = is_infinite($be) ? '∞' : (int) ceil($be) . ' draws';
    $breakEvenHtml .= "<td style='background:#1e293b;color:#fbbf24;font-weight:600;text-align:center'>{$v}</td>";
}
$breakEvenHtml .= '</tr></tbody></table>';

// ---------------------------------------------------------------------------
// Chart.js JSON
// ---------------------------------------------------------------------------

$labelsJson         = json_encode(ITEM_COUNTS);
$buildPrefixJson    = json_encode(array_values($buildPrefix));
$buildAliasJson     = json_encode(array_values($buildAlias));
$pickPrefixJson     = json_encode(array_values($pickPrefix));
$pickAliasJson      = json_encode(array_values($pickAlias));
$accPrefixJson      = json_encode(array_values($accPrefix));
$accAliasJson       = json_encode(array_values($accAlias));
$expectedMaxDevJson = json_encode(array_map(
    fn (int $n) => round(3 * sqrt(1.0 / ($n * ACCURACY_DRAWS_PER_ITEM)) * 100, 6),
    ITEM_COUNTS,
));

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
.header { padding: 1.4rem 2rem .9rem; border-bottom: 1px solid #1e293b; }
.header p { margin-top: .4rem; color: #64748b; font-size: .8rem; line-height: 1.6; }
.section { padding: .9rem 2rem; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.1rem; }
.card { background: #1e293b; border: 1px solid #334155; border-radius: .7rem; padding: 1rem; }
.card.wide { grid-column: 1 / -1; }
canvas { max-height: 240px; }
.note { color: #64748b; font-size: .72rem; margin-top: .35rem; line-height: 1.5; }

/* Heatmap */
.hmap { width: 100%; border-collapse: collapse; font-size: .75rem; }
.hmap th { background: #0f172a; color: #64748b; padding: .3rem .5rem; text-align: center;
           border: 1px solid #1e293b; font-weight: 500; white-space: nowrap; }
.hmap td { padding: .25rem .45rem; border: 1px solid #0f172a; text-align: center; white-space: nowrap; }
.draws-label { background: #1e293b !important; color: #94a3b8 !important; font-weight: 500;
               text-align: right !important; padding-right: .7rem !important; }

/* Summary */
.verdict { background: #0f2744; border: 1px solid #1d4ed8; border-radius: .7rem; padding: 1rem; margin-bottom: 1.2rem; }
.verdict h2 { color: #60a5fa; margin-bottom: .55rem; }
.gtable { width: 100%; border-collapse: collapse; font-size: .8rem; }
.gtable th { text-align: left; padding: .3rem .65rem; color: #94a3b8; border-bottom: 1px solid #1e40af; font-weight: 500; }
.gtable td { padding: .3rem .65rem; border-bottom: 1px solid #1e293b; }
.a { background:#064e3b; color:#6ee7b7; border-radius:.2rem; padding:.1rem .35rem; font-size:.7rem; font-weight:600; }
.p { background:#1e3a5f; color:#93c5fd; border-radius:.2rem; padding:.1rem .35rem; font-size:.7rem; font-weight:600; }
</style>
</head>
<body>

<div class="header">
  <h1>PrefixSumSelector vs AliasTableSelector — weighted-sample benchmark</h1>
  <p>
    重み: <b>range(1, N)</b>（不均一、Vose's アルゴリズムが実際に動く）｜
    精度: <b>equal weights</b>（N × {$accDrawsPerItem} draws）<br>
    <b>A</b> = AliasTable が速い（緑）｜ <b>P</b> = PrefixSum が速い（青）｜ 黄色ボーダー = 損益分岐点付近
  </p>
</div>

<div class="section">
  <div style="background:#0f2744;border:1px solid #1d4ed8;border-radius:.7rem;padding:1rem;margin-bottom:1.2rem">
    <h2 style="color:#60a5fa;margin-bottom:.55rem">なぜ build が遅い Alias が最終的に速くなるのか</h2>
    <p style="font-size:.82rem;line-height:1.8;color:#cbd5e1">
      <b>build は 1 回だけ</b>、<b>draw は何度でも</b> 呼ばれます。<br>
      合計コスト = <b>Build × 1回</b> + <b>Pick × D回</b><br><br>
      PrefixSum は build が速い代わりに pick が遅い。AliasTable はその逆。<br>
      draw 回数 D が増えると、pick の速度差が積み上がり、やがてビルドコスト差を上回ります。<br>
      その交差点が<b>損益分岐点</b>です: <code>D = (Build_alias − Build_prefix) / (Pick_prefix − Pick_alias)</code>
    </p>
  </div>
  <h2 style="color:#fbbf24;margin-bottom:.6rem">損益分岐点（N 別）</h2>
  {$breakEvenHtml}
  <p class="note" style="margin-top:.5rem">
    この draw 数以上なら AliasTable の O(1) pick がビルドコスト差を回収し、トータルで速くなる。
  </p>
</div>

<div class="section">
  <h2 style="margin-bottom:.6rem">ヒートマップ — draw 数 × N でどちらが有利か（比率 = Alias / PrefixSum 合計時間）</h2>
  {$heatmapHtml}
  <p class="note" style="margin-top:.5rem">
    各セルの数値 = Alias 合計時間 ÷ PrefixSum 合計時間。1.0 未満（緑）= Alias が速い。色が濃いほど差が大きい。
  </p>
</div>

<div class="section">
  <div class="grid">

    <div class="card">
      <h2>Build time (µs) vs N</h2>
      <canvas id="buildChart"></canvas>
      <p class="note">range(1,N) 重み使用。Vose's ループが動くため等重みより Alias が遅くなる。</p>
    </div>

    <div class="card">
      <h2>Pick time (µs/op) vs N</h2>
      <canvas id="pickChart"></canvas>
      <p class="note">Alias O(1) は N によらずほぼ一定。PrefixSum O(log n) は N とともに増加。</p>
    </div>

    <div class="card wide">
      <h2>Accuracy — max deviation (%) from expected — 低いほど正確</h2>
      <canvas id="accChart"></canvas>
      <p class="note">
        グレー破線 = 3σ 理論上限。両セレクタともこの範囲内 → 系統誤差なし。
        整数演算化により旧 float 版の ±1/W バイアスを排除。
      </p>
    </div>

  </div>
</div>

<div class="section">
  <div class="verdict">
    <h2>選択ガイド</h2>
    <table class="gtable">
      <tr><th>ユースケース</th><th>推奨</th><th>理由</th></tr>
      <tr>
        <td>WeightedPool — 一度 build、大量 draw</td>
        <td><span class="a">AliasTable</span></td>
        <td>build コスト1回。O(1) pick の優位が draw 数に比例して積み上がる</td>
      </tr>
      <tr>
        <td>DestructivePool / BoxPool — draw ごとに再構築</td>
        <td><span class="p">PrefixSum</span></td>
        <td>draw のたびに rebuild → build コスト支配。pick 速度差が薄れる</td>
      </tr>
      <tr>
        <td>N ≦ 1000、draw が損益分岐点以下</td>
        <td><span class="p">PrefixSum</span></td>
        <td>Alias の build オーバーヘッドを pick 速度差が回収できない</td>
      </tr>
      <tr>
        <td>N ≧ 1000、draw が損益分岐点以上</td>
        <td><span class="a">AliasTable</span></td>
        <td>O(1) pick が O(log n) を明確に上回る</td>
      </tr>
      <tr>
        <td>精度最優先（ガチャ、金融）</td>
        <td><span class="a">AliasTable</span></td>
        <td>整数演算化で float バイアス (±1/W) を排除。PrefixSum と同等以上の精度</td>
      </tr>
    </table>
  </div>
</div>

<script>
const labels = {$labelsJson};
const BASE = {
  responsive: true,
  plugins: { legend: { labels: { color: '#94a3b8', boxWidth: 13, font: { size: 10 } } } },
  scales: {
    x: { ticks:{color:'#64748b',font:{size:9}}, grid:{color:'#1e293b'},
         title:{display:true,text:'Item count (N)',color:'#64748b',font:{size:9}} },
    y: { ticks:{color:'#64748b',font:{size:9}}, grid:{color:'#1e293b'},
         title:{display:true,color:'#64748b',font:{size:9}} },
  },
};
const opt = (t) => ({...BASE, scales:{...BASE.scales, y:{...BASE.scales.y, title:{display:true,text:t,color:'#64748b',font:{size:9}}}}});

new Chart(document.getElementById('buildChart'), { type:'line', data:{ labels, datasets:[
  {label:'PrefixSum', data:{$buildPrefixJson}, borderColor:'#3b82f6', backgroundColor:'#3b82f622', tension:.3},
  {label:'Alias',     data:{$buildAliasJson},  borderColor:'#10b981', backgroundColor:'#10b98122', tension:.3},
]}, options: opt('µs / build') });

new Chart(document.getElementById('pickChart'), { type:'line', data:{ labels, datasets:[
  {label:'PrefixSum O(log n)', data:{$pickPrefixJson}, borderColor:'#3b82f6', backgroundColor:'#3b82f622', tension:.3},
  {label:'Alias O(1)',         data:{$pickAliasJson},  borderColor:'#10b981', backgroundColor:'#10b98122', tension:.3},
]}, options: opt('µs / pick') });

new Chart(document.getElementById('accChart'), { type:'line', data:{ labels, datasets:[
  {label:'PrefixSum',      data:{$accPrefixJson},      borderColor:'#3b82f6', backgroundColor:'#3b82f622', tension:.3},
  {label:'Alias',          data:{$accAliasJson},        borderColor:'#10b981', backgroundColor:'#10b98122', tension:.3},
  {label:'3σ upper bound', data:{$expectedMaxDevJson},  borderColor:'#475569', backgroundColor:'transparent', borderDash:[4,4], tension:.3, pointRadius:0},
]}, options: opt('max deviation (%)') });
</script>
</body>
</html>
HTML;

file_put_contents(__DIR__ . '/compare.html', $html);
echo "\nDone → benchmark/compare.html\n";
