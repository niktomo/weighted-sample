# weighted-sample

[English README](README.md)

PHP 8.2+ 向け重み付きランダムサンプリングライブラリ。
繰り返し抽選とボックスガチャの2つのプール型をサポートします。

[![CI](https://github.com/niktomo/weighted-sample/actions/workflows/ci.yml/badge.svg)](https://github.com/niktomo/weighted-sample/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

---

## インストール

```bash
composer require niktomo/weighted-sample
```

---

## プール型の選び方

| クラス | 抽選の挙動 | 枯渇する |
|---|---|---|
| `WeightedPool` | 毎回全アイテムから抽選 | しない |
| `BoxPool` | 各アイテムに在庫数があり、抽選ごとに在庫を消費 | する |
| `DestructivePool` | *(v2.0.0 で非推奨)* `BoxPool` + `count=1` と等価 | する |

全プールのデフォルトは `SecureRandomizer`（`\Random\Engine\Secure`）です — 設定なしで暗号学的に安全な乱数を使用します。
`SeededRandomizer(int $seed)` はテストや再現性が必要なシミュレーション専用です。

---

## WeightedPool

イミュータブルなプール。`draw()` は常に全アイテムから重みに従って抽選します。
ガチャ・抽選会など、同じアイテムを繰り返し抽選するシナリオに適しています。

```php
use WeightedSample\Pool\WeightedPool;

$pool = WeightedPool::of(
    [
        ['name' => 'SSR', 'weight' => 1],
        ['name' => 'SR',  'weight' => 9],
        ['name' => 'R',   'weight' => 90],
    ],
    fn(array $item) => $item['weight'],
);

$result = $pool->draw(); // 90% の確率で R、9% で SR、1% で SSR
```

---

## DestructivePool *(非推奨)*

> **v2.0.0 で非推奨。** 代わりに `BoxPool` + `count=1` を使用してください:
> ```php
> BoxPool::of($items, fn($item) => $item['weight'], fn($item) => 1)
> ```
> `BoxPool` + `count=1` は完全な代替です: 各アイテムは最大1回抽選され、
> 重みが大きいアイテムほど早く出やすく、`isEmpty()` / `drawMany()` の挙動も同一です。

---

## BoxPool

各アイテムに在庫数（stock）を持ちます。抽選のたびに内部カウンタが減り、
0になるとそのアイテムはプールから除外されます。枯渇時は `EmptyPoolException`。

**ユースケース:** ボックスガチャ — 景品の種類ごとに枚数が決まった閉じたセット。
`DestructivePool` と異なり、同じ「アイテム種別」を在庫数分だけ複数回抽選できます。

```php
use WeightedSample\Pool\BoxPool;

$items = [
    ['name' => 'A', 'weight' => 10, 'stock' =>  1],
    ['name' => 'B', 'weight' => 20, 'stock' =>  3],
    ['name' => 'C', 'weight' => 20, 'stock' =>  3],
    ['name' => 'D', 'weight' => 20, 'stock' =>  3],
    ['name' => 'E', 'weight' => 30, 'stock' =>  5],
    ['name' => 'F', 'weight' => 40, 'stock' => 10],
    ['name' => 'G', 'weight' => 30, 'stock' =>  5],
    ['name' => 'H', 'weight' => 30, 'stock' =>  5],
    ['name' => 'I', 'weight' => 40, 'stock' => 10],
    ['name' => 'J', 'weight' => 40, 'stock' => 10],
];

$newBox = fn() => BoxPool::of(
    $items,
    fn(array $item) => $item['weight'],
    fn(array $item) => $item['stock'],
);

// ボックスには55個の景品が入っている。プレイヤーは50連を3ラウンド引く。
//
// 第1回（  1〜 50回目）: 50回成功 — 残り5個
// 第2回（ 51〜 55回目）: 5回でボックスが空 → 新しいボックスを開封（55個）
//      （ 56〜100回目）: 新しいボックスから45回 — 残り10個
// 第3回（101〜110回目）: 10回でボックスが空 → 新しいボックスを開封（55個）
//      （111〜150回目）: 新しいボックスから40回 — 残り15個

$pool = $newBox();

for ($round = 1; $round <= 3; $round++) {
    $drawn = [];
    for ($i = 0; $i < 50; $i++) {
        if ($pool->isEmpty()) {
            $pool = $newBox(); // 新しいボックスを開封して続きを引く
        }
        $drawn[] = $pool->draw();
    }
    // $drawn をこのラウンドの結果として処理する
}
```

### drawMany()

`drawMany(int $count)` は最大 `$count` 件を一度に抽選します。
pool が `$count` 件に達する前に空になった場合は、引けた分だけ返します（例外なし）。

```php
$pool = BoxPool::of($items, fn($i) => $i['weight'], fn($i) => $i['stock']);

$prizes = $pool->drawMany(10); // 最大10件。在庫が先に切れれば途中で終了
```

> **注意:** `BoxPool` はインメモリのみです。リクエストをまたいで再開するには、
> 残在庫数を永続化し、次回リクエスト時にそのアイテムだけでプールを再構築してください。

**BoxPool の在庫管理の仕組み:**
- 生成時に渡した配列をコピーし、在庫カウンタを内部に保持します。
- `draw()` のたびに選ばれたアイテムの在庫カウンタを 1 減らします。
- カウンタが 0 になったアイテムはプールから除外されます。
- `BoxPool::of()` に渡した元の配列は一切変更されません。

---

---

## アイテムフィルター

フィルタリングは `of()` の**構築時**に適用されます — 除外されたアイテムはプールに含まれず、
`draw()` で抽選されることはありません。

デフォルトでは `weight ≤ 0` のアイテムは例外なく除外されます。`BoxPool` では `stock ≤ 0` も除外対象です。
全アイテムが除外された場合は `AllItemsFilteredException` がスローされます。

### デフォルト: PositiveValueFilter（サイレント除外）

```php
// weight=0 のアイテムは自動的に除外される（例外なし）
$pool = WeightedPool::of($items, fn(array $item) => $item['weight']);
```

### StrictValueFilter（無効値で例外をスロー）

```php
use WeightedSample\Filter\StrictValueFilter;

// weight ≤ 0 のアイテムがあると InvalidArgumentException をスロー
$pool = WeightedPool::of(
    $items,
    fn(array $item) => $item['weight'],
    filter: new StrictValueFilter(),
);
```

### CompositeFilter（複数フィルターの合成）

```php
use WeightedSample\Filter\CompositeFilter;
use WeightedSample\Filter\ItemFilterInterface;
use WeightedSample\Filter\PositiveValueFilter;

$enabledFilter = new class implements ItemFilterInterface {
    public function accepts(mixed $item, int $weight): bool
    {
        return $item['enabled'] === true;
    }
};

$pool = WeightedPool::of(
    $items,
    fn(array $item) => $item['weight'],
    filter: new CompositeFilter([new PositiveValueFilter(), $enabledFilter]),
);
```

### BoxPool 用カスタムフィルター

`BoxPool` は `CountedItemFilterInterface` を要求します。これは `ItemFilterInterface` を拡張し、
weight に加えて在庫数も受け取る `acceptsWithCount()` メソッドを持ちます。

```php
use WeightedSample\Filter\CountedItemFilterInterface;

$activeFilter = new class implements CountedItemFilterInterface {
    public function accepts(mixed $item, int $weight): bool
    {
        return $item['enabled'] === true && $weight > 0;
    }

    public function acceptsWithCount(mixed $item, int $weight, int $count): bool
    {
        return $this->accepts($item, $weight) && $count > 0;
    }
};

$pool = BoxPool::of(
    $items,
    fn(array $item) => $item['weight'],
    fn(array $item) => $item['stock'],
    filter: $activeFilter,
);
```

**注意:** `CompositeFilter` を `BoxPool` で使う場合、`ItemFilterInterface` のみを実装した内部フィルター（`CountedItemFilterInterface` 未実装）は `acceptsWithCount()` が `accepts()` にフォールバックし、在庫数が無視されます。在庫数による除外が必要なフィルターは `CountedItemFilterInterface` を実装してください。

### 例外の種類

| 例外 | スローされるタイミング |
|---|---|
| `AllItemsFilteredException` | 構築時: フィルターによって全アイテムが除外された |
| `EmptyPoolException` | 実行時: 枯渇したプールで `draw()` を呼んだ |

`AllItemsFilteredException` は `EmptyPoolException` のサブクラスです。
既存の `catch (EmptyPoolException)` ブロックはそのまま動作します。

---

## 大規模データのストリーミング（iterable サポート）

全プールの `of()` メソッドは配列だけでなく `iterable`（ジェネレータを含む）を受け付けます。
ジェネレータを渡すと、元コレクションとプールの両方を同時にメモリに持つことを避けられます。

```php
// DBカーソルから全行を配列にバッファリングせずに供給する
function itemsFromDb(\PDOStatement $stmt): \Generator
{
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        yield $row;
    }
}

$pool = WeightedPool::of(itemsFromDb($stmt), fn(array $row) => $row['weight']);
```

注意: プール自体は受け入れたアイテムを内部に保持するため、
構築後のメモリ使用量は受け入れアイテム数に比例します。

---

## シード付きランダマイザー

シード固定のランダマイザーを注入することで、テストやシミュレーションで再現性のある結果を得られます。

```php
use WeightedSample\Randomizer\SeededRandomizer;

$pool = WeightedPool::of(
    $items,
    fn(array $item) => $item['weight'],
    randomizer: new SeededRandomizer(42), // 固定シード
);

// seed=42 では毎回同じ順序で抽選される
$result = $pool->draw();
```

シードなしの場合は `\Random\Engine\Secure` を使用し、暗号学的に安全な乱数を生成します。

> **注意:** シードを指定した `SeededRandomizer` は内部で `Mt19937` を使用します。
> `Mt19937` は**暗号学的に安全ではありません**。固定シードはテストや再現性が必要なシミュレーション専用とし、
> 本番環境の抽選には使用しないでください。

---

## セレクターアルゴリズム

3つの選択アルゴリズムを提供しています。
`selectorFactory`（WeightedPool）または `selectorBundleFactory`（BoxPool）で差し替え可能です。

| セレクター / ファクトリ | 構築 | 抽選 | 更新 | 適した用途 |
|---|---|---|---|---|
| `PrefixSumSelectorFactory`（WeightedPool デフォルト） | O(n) | O(log n) | O(n) 再構築 | WeightedPool、小規模 BoxPool |
| `AliasTableSelectorFactory` | O(n) | O(1) | O(n) 再構築 | draw 回数が多い WeightedPool |
| `FenwickSelectorBundleFactory`（BoxPool デフォルト） | O(n) | O(log n) | **O(log n)** | N ≥ 500 の BoxPool |
| `RebuildSelectorBundleFactory` | O(n) | O(log n) | O(n) 再構築 | AliasTable pick が必要な BoxPool |

3つすべて整数演算のみ — float による丸め誤差なし。

```php
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Pool\BoxPool;
use WeightedSample\Selector\AliasTableSelectorFactory;
use WeightedSample\Builder\FenwickSelectorBundleFactory;
use WeightedSample\Builder\RebuildSelectorBundleFactory;

// draw 回数が多い WeightedPool — Alias で O(1) 抽選
$pool = WeightedPool::of(
    $items,
    fn(array $item) => $item['weight'],
    selectorFactory: new AliasTableSelectorFactory(),
);

// 大規模 BoxPool — FenwickSelectorBundleFactory がデフォルトで O(n log n)
$pool = BoxPool::of(
    $items,
    fn(array $item) => $item['weight'],
    fn(array $item) => $item['stock'],
    selectorBundleFactory: new FenwickSelectorBundleFactory(),
);

// BoxPool で AliasTable pick（O(1)）が必要な場合
$pool = BoxPool::of(
    $items,
    fn(array $item) => $item['weight'],
    fn(array $item) => $item['stock'],
    selectorBundleFactory: new RebuildSelectorBundleFactory(new AliasTableSelectorFactory()),
);
```

---

### 各セレクターの使い分け

**PrefixSumSelector（デフォルト）**
ほぼすべてのガチャ・抽選ユースケースに適した選択肢です。ビルドオーバーヘッドが小さく、
O(log n) の抽選速度は数百件程度の典型的な draw 回数では十分高速です。

**AliasTableSelector**
ビルドコストは PrefixSum の約 2.8 倍です。この初期コストは、**同じプールインスタンス**
から `draw()` を多数回呼ぶ場合（= 長期利用の `WeightedPool`）にのみ回収できます。

ベンチマークによる損益分岐点の目安：

| アイテム数 | 損益分岐点（draw 回数） |
|-----------|----------------------|
|    50 | 約 40 回  |
|   100 | 約 65 回  |
|  1000 | 約 440 回 |

リクエストごと・セッションごとにプールを再構築して数回しか引かない典型的なガチャでは、
Alias はビルドコストを回収できません。その場合は `PrefixSumSelector` を使ってください。

> **注意:** `DestructivePool` / `BoxPool` で Alias を使うと `update()` 非対応のため
> draw のたびに O(n) の全再構築が発生し、合計コストが O(n²) になります。
> これらのプールには `FenwickTreeSelector` を使ってください。

**FenwickTreeSelector / FenwickSelectorBundleFactory**
`FenwickSelectorBundleFactory`（BoxPool のデフォルト）は `FenwickTreeSelector` と
`FenwickSelectorBuilder` をペアリングします。アイテムが枯渇するとビルダーが
O(log n) の `update(index, 0)` を呼び、セレクタ全体の再構築を不要にします —
全アイテム draw の合計コストが O(n²) から O(n log n) に改善されます。

| N    | RebuildBuilder（µs/アイテム） | FenwickBuilder（µs/アイテム） | 高速化 |
|------|-----------------------------:|-----------------------------:|-------:|
|  100 |                       ~3.3   |                      ~0.9    |  ~3.5倍 |
|  500 |                      ~11.4   |                      ~1.4    |  ~8.2倍 |
| 1000 |                      ~21.3   |                      ~1.9    | ~11.1倍 |
| 5000 |                     ~100.1   |                      ~6.0    | ~16.8倍 |

`BoxPool` で N ≥ 500 の場合は `FenwickSelectorBundleFactory`（デフォルト）を使ってください。
イミュータブルな `WeightedPool` では Fenwick のツリー降下は prefix sum の二分探索より
オーバーヘッドが大きいため、`PrefixSumSelectorFactory` か `AliasTableSelectorFactory` を使ってください。

> **オーバーフロー制約:**
> - `PrefixSumSelector` / `FenwickTreeSelector`: 合計重み W が `PHP_INT_MAX` 以下であること
> - `AliasTableSelector`: さらに `n × W ≤ PHP_INT_MAX`（n = アイテム数）が必要

### 速度比較 — 各アルゴリズムで 20万回抽選（重み: range(1, N)）

```
アイテム数   PrefixSum O(log n)   Fenwick O(log n)   Alias O(1)
       10         ~0.138 µs          ~0.139 µs      ~0.102 µs
       50         ~0.170 µs          ~0.173 µs      ~0.106 µs
      100         ~0.200 µs          ~0.217 µs      ~0.110 µs
      500         ~0.350 µs          ~0.401 µs      ~0.111 µs
     1000         ~0.366 µs          ~0.436 µs      ~0.110 µs
     5000         ~0.430 µs          ~0.532 µs      ~0.112 µs
    50000         ~0.517 µs          ~0.680 µs      ~0.119 µs
```

Alias の抽選スループットはアイテム数に関わらずほぼ一定（真の O(1)）。
PrefixSum と Fenwick は O(log n) で増加。イミュータブルプールでは PrefixSum の方が Fenwick より高速。
`php benchmark/compare.php` でアイテム数・draw 数別の損益分岐点と BoxPool スケーリングを確認できる。

### 精度比較 — N×500回抽選での最大偏差（均等重み、seed=99）

```
N        PrefixSum    Fenwick    Alias
   50     ~0.22%      ~0.22%    ~0.24%
  100     ~0.13%      ~0.13%    ~0.15%
 1000     ~0.02%      ~0.02%    ~0.02%
```

3つのアルゴリズムはすべて統計的に同等です。偏差はアルゴリズムの誤差ではなく、
純粋なサンプリングノイズです。どれも確率0のアイテムが抽選されることはありません。

---

## ベンチマーク結果

**WeightedPool — 100万回抽選（SSR=1%, SR=9%, R=90%）**

```
Item        Draws    Actual%  Expected%     Diff
SSR         10042     1.004%     1.000%   +0.004%
SR          89893     8.989%     9.000%   -0.011%
R          900065    90.007%    90.000%   +0.007%
```

**WeightedPool — 100アイテム（weight 1〜100）、100万回抽選**

```
最大偏差: ~0.026%（0.5ポイントを超えたアイテムはなし）
```

ベンチマークを実行する場合：

```bash
docker compose run --rm benchmark
# Docker なしの場合:
php benchmark/run.php
```

---

## 型安全性

PHPStan level 8 に完全対応。`@template T` によるジェネリクスで、`draw()` の戻り値の型が静的解析で追跡されます。

```php
/** @var WeightedPool<array{name: string, weight: int}> $pool */
$pool = WeightedPool::of($items, fn(array $item) => $item['weight']);

$item = $pool->draw(); // PHPStan は array{name: string, weight: int} と推論
```

フィルターインターフェースもジェネリクス対応しています。`ItemFilterInterface<T>` および
`CountedItemFilterInterface<T>` はアイテムの型を静的解析で追跡します。

---

## 要件

- PHP 8.2+
- 実行時依存なし

## ライセンス

MIT
