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

2つの選択アルゴリズムを `SelectorInterface` の実装として提供しています。
全プールの `selectorClass` 名前付き引数で差し替え可能です。

| セレクター | 構築 | 抽選 | 演算 |
|---|---|---|---|
| `PrefixSumSelector`（デフォルト） | O(n) | O(log n) | 完全整数 |
| `AliasTableSelector` | O(n) | O(1) | 完全整数 |

```php
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Selector\AliasTableSelector;

$pool = WeightedPool::of(
    $items,
    fn(array $item) => $item['weight'],
    selectorClass: AliasTableSelector::class, // O(1) エイリアス法に差し替え
);
```

**AliasTableSelector を選ぶとき:**
Alias のビルドコストは PrefixSum の約 2.8 倍です。この初期コストは、**同じプールインスタンス**
から `draw()` を多数回呼ぶ場合（= 長期利用の `WeightedPool`）にのみ回収できます。

ベンチマークによる損益分岐点の目安：

| アイテム数 | 損益分岐点（draw 回数） |
|-----------|----------------------|
|    50 | 約 40 回  |
|   100 | 約 65 回  |
|  1000 | 約 440 回 |

リクエストごと・セッションごとにプールを再構築して数回しか引かない典型的なガチャでは、
Alias はビルドコストを回収できません。その場合は `PrefixSumSelector` を使ってください。

`DestructivePool` と `BoxPool` は `draw()` のたびにセレクタを再構築するため、
アイテム数に関わらず `AliasTableSelector` のメリットはありません。

**PrefixSumSelector（デフォルト）を選ぶとき:**
ほぼすべてのガチャ・抽選ユースケースに適した選択肢です。ビルドオーバーヘッドが小さく、
O(log n) の抽選速度は数百件程度の典型的な draw 回数では十分高速です。

> **注意:** `AliasTableSelector` は `n × W ≤ PHP_INT_MAX`（n = アイテム数、W = 重みの合計）が必要です。
> `PrefixSumSelector` は `W ≤ PHP_INT_MAX` のみを要求するため、同じ合計重みに対してより多くのアイテムを扱えます。
> ただし両セレクターとも n 件分のデータをメモリに保持します。

### 速度比較 — 各アルゴリズムで 20万回抽選（重み: range(1, N)）

```
アイテム数   PrefixSum O(log n)   Alias O(1)   倍率
   100             ~266 ms         ~105 ms      2.5倍速
  1000             ~333 ms         ~103 ms      3.2倍速
  2500             ~350 ms         ~107 ms      3.3倍速
  5000             ~382 ms         ~108 ms      3.5倍速
 10000             ~394 ms         ~110 ms      3.6倍速
 50000             ~465 ms         ~114 ms      4.1倍速
```

Alias の抽選スループットはアイテム数に関わらずほぼ一定（真の O(1)）。
ビルド時間は Alias が約 2.8倍遅いが、これは draw 回数に応じて償却される初期コスト。
`php benchmark/compare.php` でアイテム数・draw 数別の損益分岐点を確認できる。

### 精度比較 — 100万回抽選での最大偏差（seed=42）

```
重み分布                     PrefixSum    Alias
1/3 ずつ [1,1,1]             ~0.057%    ~0.035%
1/7, 2/7, 4/7 [1,2,4]       ~0.061%    ~0.032%
1/5 ずつ [1,1,1,1,1]         ~0.048%    ~0.090%
```

両アルゴリズムは統計的に同等です。偏差はアルゴリズムの誤差ではなく、
純粋なサンプリングノイズです。どちらも確率0のアイテムが抽選されることはありません。

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
