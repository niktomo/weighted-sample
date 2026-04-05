# weighted-sample

[English README](README.md)

PHP 8.2+ 向け重み付きランダムサンプリングライブラリ。
繰り返し抽選・破壊的抽選・ボックスガチャの3つのプール型をサポートします。

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
| `DestructivePool` | 各アイテムは1回のみ抽選可能。抽選済みアイテムは除外 | する |
| `BoxPool` | 各アイテムに在庫数があり、抽選ごとに在庫を消費 | する |

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

## DestructivePool

抽選したアイテムはプールから完全に除外されます。
全アイテムを引き切った後に `draw()` を呼ぶと `EmptyPoolException` がスローされます。

**ユースケース:** 重み付きシャッフル — 全アイテムがいずれ必ず抽選されますが、
重みが大きいアイテムほど早い順番で出やすくなります。

```php
use WeightedSample\Pool\DestructivePool;
use WeightedSample\Exception\EmptyPoolException;

$pool = DestructivePool::of(
    [
        ['id' => 1, 'weight' => 10],
        ['id' => 2, 'weight' => 30],
        ['id' => 3, 'weight' => 60],
    ],
    fn(array $item) => $item['weight'],
);

// 全アイテムを重み付きランダム順で抽選 — 各アイテムは必ず1回だけ出る
$order = [];
while (! $pool->isEmpty()) {
    $order[] = $pool->draw()['id']; // 例: [3, 2, 1] や [3, 1, 2] など
}

// 元の配列は変更されない — DestructivePool は内部コピーで動作する
```

---

## BoxPool

各アイテムに在庫数（stock）を持ちます。抽選のたびに内部カウンタが減り、
0になるとそのアイテムはプールから除外されます。枯渇時は `EmptyPoolException`。

**ユースケース:** ボックスガチャ — 景品の種類ごとに枚数が決まった閉じたセット。
`DestructivePool` と異なり、同じ「アイテム種別」を在庫数分だけ複数回抽選できます。

```php
use WeightedSample\Pool\BoxPool;

// 合計10枚: Gold×1, Silver×3, Bronze×6
$pool = BoxPool::of(
    [
        ['name' => 'Gold',   'weight' => 10, 'stock' => 1],
        ['name' => 'Silver', 'weight' => 30, 'stock' => 3],
        ['name' => 'Bronze', 'weight' => 60, 'stock' => 6],
    ],
    fn(array $item) => $item['weight'],
    fn(array $item) => $item['stock'],      // <-- 在庫数を返すクロージャ
);

// ボックスが空になるまで引く（合計10回）
while (! $pool->isEmpty()) {
    $item = $pool->draw(); // 抽選したアイテムを返す。在庫は内部で管理
}

// 元の配列は変更されない — BoxPool は生成時にデータをコピーする
```

**BoxPool の在庫管理の仕組み:**
- 生成時に渡した配列をコピーし、在庫カウンタを内部に保持します。
- `draw()` のたびに選ばれたアイテムの在庫カウンタを 1 減らします。
- カウンタが 0 になったアイテムはプールから除外されます。
- `BoxPool::of()` に渡した元の配列は一切変更されません。

---

## DestructivePool vs BoxPool

| | `DestructivePool` | `BoxPool` |
|---|---|---|
| アイテムの扱い | 各アイテムは一意（重複なし） | 同じ種別を在庫数分だけ複数回抽選可能 |
| 抽選後の挙動 | アイテムを即時除外 | 在庫を 1 消費し、0 になれば除外 |
| 合計抽選回数 | アイテム数と同じ | 全在庫数の合計と同じ |
| 典型的なユースケース | くじ引き・シャッフル再生 | ボックスガチャ・在庫管理 |

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

3つの選択アルゴリズムを `SelectorInterface` の実装として提供しています。
全プールの `selectorClass` 名前付き引数で差し替え可能です。

| セレクター | 構築 | 抽選 | 更新 | 適した用途 |
|---|---|---|---|---|
| `PrefixSumSelector`（デフォルト） | O(n) | O(log n) | O(n) 再構築 | WeightedPool、小規模 DestructivePool/BoxPool |
| `AliasTableSelector` | O(n) | O(1) | O(n) 再構築 | draw 回数が多い WeightedPool |
| `FenwickTreeSelector` | O(n) | O(log n) | **O(log n)** | N ≥ 500 の DestructivePool / BoxPool |

3つすべて整数演算のみ — float による丸め誤差なし。

```php
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Pool\DestructivePool;
use WeightedSample\Selector\AliasTableSelector;
use WeightedSample\Selector\FenwickTreeSelector;

// draw 回数が多い WeightedPool — Alias で O(1) 抽選
$pool = WeightedPool::of(
    $items,
    fn(array $item) => $item['weight'],
    selectorClass: AliasTableSelector::class,
);

// 大規模 DestructivePool — FenwickTree で O(n log n) に抑える
$pool = DestructivePool::of(
    $items,
    fn(array $item) => $item['weight'],
    selectorClass: FenwickTreeSelector::class,
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

**FenwickTreeSelector**
`UpdatableSelectorInterface` を実装しており、`update(int $index, int $newWeight): void`
メソッドを持ちます。`DestructivePool` と `BoxPool` はこれを使って抽選済みアイテムを
O(log n) で除外します — セレクタ全体の再構築が不要になり、全アイテム draw の合計コストが
O(n²) から O(n log n) に改善されます。

| N    | PrefixSum（µs/アイテム） | Fenwick（µs/アイテム） | 高速化 |
|------|--------------------:|------------------:|-------:|
|  100 |              ~1.6   |             ~0.7  |   ~2倍 |
|  500 |              ~4.9   |             ~0.7  |   ~7倍 |
| 1000 |              ~9.3   |             ~0.8  |  ~12倍 |
| 5000 |             ~45.0   |             ~0.9  |  ~48倍 |

`DestructivePool` / `BoxPool` で N ≥ 500 の場合は `FenwickTreeSelector` を使ってください。
イミュータブルな `WeightedPool` では Fenwick のツリー降下は prefix sum の二分探索より
オーバーヘッドが大きいため、`PrefixSumSelector` か `AliasTableSelector` を使ってください。

> **オーバーフロー制約:**
> - `PrefixSumSelector` / `FenwickTreeSelector`: 合計重み W が `PHP_INT_MAX` 以下であること
> - `AliasTableSelector`: さらに `n × W ≤ PHP_INT_MAX`（n = アイテム数）が必要

### 速度比較 — 各アルゴリズムで 20万回抽選（重み: range(1, N)）

```
アイテム数   PrefixSum O(log n)   Fenwick O(log n)   Alias O(1)
       10         ~0.200 µs          ~0.209 µs      ~0.104 µs
       50         ~0.260 µs          ~0.289 µs      ~0.107 µs
      100         ~0.287 µs          ~0.321 µs      ~0.106 µs
      500         ~0.332 µs          ~0.385 µs      ~0.105 µs
     1000         ~0.355 µs          ~0.415 µs      ~0.107 µs
     5000         ~0.396 µs          ~0.516 µs      ~0.112 µs
    50000         ~0.480 µs          ~0.660 µs      ~0.115 µs
```

Alias の抽選スループットはアイテム数に関わらずほぼ一定（真の O(1)）。
PrefixSum と Fenwick は O(log n) で増加。イミュータブルプールでは PrefixSum の方が Fenwick より高速。
`php benchmark/compare.php` でアイテム数・draw 数別の損益分岐点と DestructivePool スケーリングを確認できる。

### 精度比較 — 10万回抽選での最大偏差（均等重み、seed=42）

```
N        PrefixSum    Fenwick    Alias
   10     ~1.06%      ~1.06%    ~1.12%
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
SSR         10034     1.003%     1.000%   +0.003%
SR          90107     9.011%     9.000%   +0.011%
R          899859    89.986%    90.000%   -0.014%
```

**WeightedPool — 100アイテム（weight 1〜100）、100万回抽選**

```
最大偏差: ~0.024%（0.5ポイントを超えたアイテムはなし）
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
