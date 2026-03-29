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
| `DestructivePool` | 抽選したアイテムをプールから除外 | する |
| `BoxPool` | 各アイテムに個数があり、0になると除外 | する |

---

## WeightedPool

イミュータブルなプール。`draw()` は常に全アイテムから重みに従って抽選します。
ガチャ・抽選会など、同じアイテムを繰り返し抽選するシナリオに適しています。

```php
use WeightedSample\Pool\WeightedPool;

$pool = WeightedPool::of(
    [
        ['name' => 'SSR', 'weight' => 5],
        ['name' => 'SR',  'weight' => 15],
        ['name' => 'R',   'weight' => 80],
    ],
    fn($item) => $item['weight'],
);

$result = $pool->draw(); // ['name' => 'R', 'weight' => 80]  (最も確率が高い)
```

---

## DestructivePool

抽選したアイテムがプールから除外されます。枯渇すると `EmptyPoolException` がスローされます。
くじ引き・シャッフル抽選など、各アイテムを1度だけ抽選するシナリオに適しています。

```php
use WeightedSample\Pool\DestructivePool;
use WeightedSample\Exception\EmptyPoolException;

$pool = DestructivePool::of(
    [
        ['id' => 1, 'weight' => 10],
        ['id' => 2, 'weight' => 30],
        ['id' => 3, 'weight' => 60],
    ],
    fn($item) => $item['weight'],
);

while (! $pool->isEmpty()) {
    $item = $pool->draw(); // 各アイテムが正確に1回抽選される
}
```

---

## BoxPool

各アイテムに個数（count）を持ちます。抽選のたびに個数が減り、0になるとプールから除外されます。
ボックスガチャ・在庫制限・数量固定の景品プールに適しています。

```php
use WeightedSample\Pool\BoxPool;

$pool = BoxPool::of(
    [
        ['name' => 'Gold',   'weight' => 10, 'stock' => 1],
        ['name' => 'Silver', 'weight' => 30, 'stock' => 3],
        ['name' => 'Bronze', 'weight' => 60, 'stock' => 6],
    ],
    fn($item) => $item['weight'],
    fn($item) => $item['stock'],
);

while (! $pool->isEmpty()) {
    $item = $pool->draw(); // 合計10回抽選できる (1 + 3 + 6)
}
```

---

## エラーハンドリング

`EmptyPoolException` がスローされるのは以下の2つのケースです：

1. フィルタリング後にアイテムが0件になった状態でプールを生成しようとした
2. 枯渇した `DestructivePool` または `BoxPool` で `draw()` を呼んだ

```php
use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Pool\DestructivePool;

try {
    $pool = DestructivePool::of($items, fn($i) => $i['weight']);
    while (! $pool->isEmpty()) {
        $item = $pool->draw();
    }
} catch (EmptyPoolException $e) {
    // プール枯渇時の処理
}
```

---

## アイテムフィルター

デフォルトでは `weight ≤ 0`（`BoxPool` では `count ≤ 0`）のアイテムは例外なく除外されます。
カスタムフィルターを注入して挙動を変更できます。

### デフォルト: PositiveValueFilter（サイレント除外）

```php
use WeightedSample\Filter\PositiveValueFilter;

// weight=0 のアイテムは自動的に除外される（例外なし）
$pool = WeightedPool::of($items, fn($i) => $i['weight']);
```

### StrictValueFilter（無効値で例外をスロー）

```php
use WeightedSample\Filter\StrictValueFilter;

// weight ≤ 0 のアイテムがあると InvalidArgumentException をスロー
$pool = WeightedPool::of(
    $items,
    fn($i) => $i['weight'],
    filter: new StrictValueFilter(),
);
```

### CompositeFilter（複数フィルターの合成）

```php
use WeightedSample\Filter\CompositeFilter;
use WeightedSample\Filter\PositiveValueFilter;

$enabledFilter = new class implements \WeightedSample\Filter\ItemFilterInterface {
    public function accepts(mixed $item, int $weight, ?int $count): bool
    {
        return $item['enabled'] === true;
    }
};

$pool = WeightedPool::of(
    $items,
    fn($i) => $i['weight'],
    filter: new CompositeFilter([new PositiveValueFilter(), $enabledFilter]),
);
```

---

## シード付きランダマイザー

シード固定のランダマイザーを注入することで、テストやシミュレーションで再現性のある結果を得られます。

```php
use WeightedSample\Randomizer\SeededRandomizer;

$pool = WeightedPool::of(
    $items,
    fn($i) => $i['weight'],
    randomizer: new SeededRandomizer(42), // 固定シード
);

// seed=42 では毎回同じ順序で抽選される
$result = $pool->draw();
```

シードなしの場合は `\Random\Engine\Secure` を使用し、暗号学的に安全な乱数を生成します。

---

## 重み分布

すべての抽選は **prefix sum + binary search**（O(log n)）で整数演算のみを用いて実現しています。浮動小数点の丸め誤差はありません。

### ベンチマーク結果

**WeightedPool — 100万回抽選（SSR=1%, SR=9%, R=90%）**

```
Item        Draws    Actual%  Expected%     Diff
SSR         10050     1.005%     1.000%   +0.005%
SR          90058     9.006%     9.000%   +0.006%
R          899892    89.989%    90.000%   -0.011%
```

**WeightedPool — 100アイテム（weight 1〜100）、100万回抽選**

```
Item        Draws    Actual%  Expected%     Diff
w1            207     0.021%     0.020%   +0.001%
w2            425     0.042%     0.040%   +0.003%
...
w100        19973     1.997%     1.980%   +0.017%
最大偏差: 0.0338%
```

ベンチマークを自分で実行する場合：

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
$pool = WeightedPool::of($items, fn($i) => $i['weight']);

$item = $pool->draw(); // PHPStan は array{name: string, weight: int} と推論
```

---

## 要件

- PHP 8.2+
- 実行時依存なし

## ライセンス

MIT
