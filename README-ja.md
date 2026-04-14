# weighted-sample

[English README](README.md)

PHP 8.2+ 向け重み付きランダムサンプリングライブラリ。
繰り返し抽選・ボックスガチャの2つのプール型をサポートします。

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

全プールのデフォルトは `SecureRandomizer`（`\Random\Engine\Secure`）です — 設定なしで暗号学的に安全な乱数を使用します。
`SeededRandomizer(int $seed)` はテストや再現性が必要なシミュレーション専用です。

---

## WeightedPool

イミュータブルなプール。`draw()` は常に全アイテムから重みに従って抽選します。
ガチャ・抽選会など、同じアイテムを繰り返し抽選するシナリオに適しています。

**アイテムの形は自由**です — 連想配列・オブジェクト・スカラー値、どれでも動きます。
第2引数はアイテムから整数の重みを取り出すクロージャです：

```php
use WeightedSample\Pool\WeightedPool;

// 連想配列 — キー名は何でも OK
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

クロージャはプロパティやメソッドを何でも参照できます：

```php
use WeightedSample\Pool\WeightedPool;

// オブジェクト
$pool = WeightedPool::of($prizes, fn(Prize $p): int => $p->rarityWeight());

// スカラー値 — アイテム自体が重み
$pool = WeightedPool::of([10, 30, 60], fn(int $w) => $w);
```

---

## BoxPool

各アイテムに在庫数（stock）を持ちます。抽選のたびに内部カウンタが減り、
0になるとそのアイテムはプールから除外されます。枯渇時は `EmptyPoolException`。

**ユースケース:** ボックスガチャ — 景品の種類ごとに枚数が決まった閉じたセット。
同じ「アイテム種別」を在庫数分だけ複数回抽選できます。

BoxPool は **2つのクロージャ** が必要です：抽選重み用と在庫数用。
アイテムの形は自由 — クロージャで正しいフィールドを指定するだけです：

```php
use WeightedSample\Pool\BoxPool;

// fn($item) => 重み,  fn($item) => 在庫数
$pool = BoxPool::of(
    $items,
    fn(array $item) => $item['weight'],  // 抽選確率
    fn(array $item) => $item['stock'],   // 何回抽選できるか
);
```

---

### BoxPool サンプルコード

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
use WeightedSample\Pool\BoxPool;

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

## アイテムフィルター

フィルタリングは `of()` の**構築時**に適用されます — 除外されたアイテムはプールに含まれず、
`draw()` で抽選されることはありません。

デフォルトでは `weight ≤ 0` のアイテムは例外なく除外されます。`BoxPool` では `stock ≤ 0` も除外対象です。
全アイテムが除外された場合は `InvalidArgumentException` がスローされます。

### デフォルト: PositiveValueFilter（サイレント除外）

```php
use WeightedSample\Pool\WeightedPool;

// weight=0 のアイテムは自動的に除外される（例外なし）
$pool = WeightedPool::of($items, fn(array $item) => $item['weight']);
```

### StrictValueFilter（無効値で例外をスロー）

```php
use WeightedSample\Filter\StrictValueFilter;
use WeightedSample\Pool\WeightedPool;

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
use WeightedSample\Pool\WeightedPool;

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
use WeightedSample\Pool\BoxPool;

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
| `InvalidArgumentException` | 構築時: `of()` に渡したアイテムがフィルターで全除外された |
| `EmptyPoolException` | 実行時: 枯渇したプールで `draw()` を呼んだ |

構築失敗と実行時枯渇は異なる失敗モードです。`InvalidArgumentException` は `of()` への入力が不正（有効なアイテムがゼロ件）であることを意味し、`EmptyPoolException` はプールが使用中に枯渇したことを意味します。

```php
use InvalidArgumentException;
use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Pool\WeightedPool;

// 構築時: 有効なアイテムが残らない場合に InvalidArgumentException
// PositiveValueFilter（デフォルト）は weight=0 のアイテムを除外します。
try {
    $pool = WeightedPool::of($items, fn(array $item) => $item['weight']);
} catch (InvalidArgumentException $e) {
    // 入力が不正 — 全アイテムが除外されました。データやフィルター設定を確認してください。
    return;
}

// 実行時は EmptyPoolException で枯渇を検知する:
try {
    while (true) {
        $winner = $pool->draw();
    }
} catch (EmptyPoolException $e) {
    // プールが枯渇しました — 全アイテムが抽選済みです。
}
```

---

## 大規模データのストリーミング（iterable サポート）

全プールの `of()` メソッドは配列だけでなく `iterable`（ジェネレータを含む）を受け付けます。
ジェネレータを渡すと、元コレクションとプールの両方を同時にメモリに持つことを避けられます。

```php
use WeightedSample\Pool\WeightedPool;

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
use WeightedSample\Pool\WeightedPool;
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

> **警告:** `SeededRandomizer` は内部で `Mt19937` を使用します。`Mt19937` は**暗号学的に安全ではありません**。
> 使用前に以下を**すべて**確認してください：
>
> - [ ] このコードはテストまたはオフラインシミュレーション専用 — 本番のリクエストハンドラでは実行しない
> - [ ] シードを `time()` / `microtime()` などのタイムスタンプから生成していない（1 秒以内にブルートフォース可能）
> - [ ] シードを複数ユーザー・リクエスト間で共有していない（同じシード → 同一の抽選列）
> - [ ] ユーザーが十分な出力結果を観測できない（連続 624 個の 32bit 出力で内部状態を完全復元可能）
>
> 1 つでも当てはまる場合は `SecureRandomizer`（デフォルト）を使用してください。

**安全なシード導出**（オフラインシミュレーション専用）：連番カウンターやタイムスタンプではなく、高エントロピーの値からシードを導出してください。

```php
// 一回限りのランダムな 32bit シード
$seed = unpack('N', random_bytes(4))[1];
// — または — エンティティごとに再現可能なシードを導出する
$seed = (int) hexdec(substr(hash('sha256', $userId . ':' . $campaignId), 0, 8));
```

---

## カスタマイズ

主要な振る舞いはすべてインターフェースで定義されています — 名前付き引数で任意の実装に差し替え可能です。
独自のフィルター・ランダマイザー・セレクターアルゴリズムを実装して注入することもできます。

| パラメータ | インターフェース | デフォルト | 変更する理由 |
|---|---|---|---|
| `randomizer:` | `RandomizerInterface` | `SecureRandomizer`（OS CSPRNG） | テスト・再現シミュレーションで `SeededRandomizer` を使いたい |
| `filter:` | `ItemFilterInterface<T>` | `PositiveValueFilter` | 独自の除外ルールを追加したい、`CompositeFilter` で複数合成したい |
| `selectorFactory:` *(WeightedPool)* | `SelectorFactoryInterface` | `PrefixSumSelectorFactory` | draw 回数が多い大規模プールで `AliasTableSelectorFactory` に切り替えたい |
| `bundleFactory:` *(BoxPool)* | `SelectorBundleFactoryInterface` | `FenwickSelectorBundleFactory` | ビルダー戦略を変えたい（極小プール N ≤ 50 で `RebuildSelectorBundleFactory` など） |

```php
use WeightedSample\Builder\FenwickSelectorBundleFactory;
use WeightedSample\Filter\StrictValueFilter;
use WeightedSample\Pool\BoxPool;
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\AliasTableSelectorFactory;

// ランダマイザーを差し替える（テスト用）
$pool = WeightedPool::of($items, fn(array $i) => $i['weight'],
    randomizer: new SeededRandomizer(42),
);

// フィルターを差し替える
$pool = WeightedPool::of($items, fn(array $i) => $i['weight'],
    filter: new StrictValueFilter(),
);

// セレクターアルゴリズムを差し替える（WeightedPool）
$pool = WeightedPool::of($items, fn(array $i) => $i['weight'],
    selectorFactory: new AliasTableSelectorFactory(),
);

// ビルダー戦略を差し替える（BoxPool）
$pool = BoxPool::of($items, fn(array $i) => $i['weight'], fn(array $i) => $i['stock'],
    bundleFactory: new FenwickSelectorBundleFactory(), // デフォルト値（明示用）
);
```

---

## セレクターアルゴリズム

3つの選択アルゴリズムを提供しています。
`selectorFactory`（WeightedPool）または `bundleFactory`（BoxPool）で差し替え可能です。

### クイック選択ガイド

**まずはデフォルトを使ってください。** パフォーマンス上の問題を計測してから変更を検討してください。

| プール | アイテム数（N） | プール1回あたりの draw 回数 | 推奨 |
|---|---|---|---|
| `WeightedPool` | 問わず | 少ない（< 約60回） | `PrefixSumSelectorFactory` — **デフォルト**、万能 |
| `WeightedPool` | 問わず | 多い（≥ 約60回） | `AliasTableSelectorFactory` — O(1) 抽選でコスト回収 |
| `BoxPool` | 問わず | — | `FenwickSelectorBundleFactory` — **デフォルト**、O(log n) 更新で10,000件超に対応 |
| `BoxPool` | ≤ 50 | — | `RebuildSelectorBundleFactory(new AliasTableSelectorFactory())` — まれな枯渇間は O(1) 抽選 |

> **高負荷・大規模 N？** `BoxPool` なら `FenwickSelectorBundleFactory` が正解です。
> アイテム枯渇のたびにかかるコストが O(log n) になり、N=10,000 でも全抽選コストが O(n log n) に収まります。
> 後述の [FenwickBuilder 高速化表](#fenwicktreeselector--fenwickselectorbundlefactory) を参照してください。

### 計算量早見表

| セレクター / ファクトリ | 構築 | 抽選 | 更新（枯渇時） |
|---|---|---|---|
| `PrefixSumSelectorFactory` *（WeightedPool デフォルト）* | O(n) | O(log n) | O(n) 全再構築 |
| `AliasTableSelectorFactory` | O(n) | **O(1)** | O(n) 全再構築 |
| `FenwickSelectorBundleFactory` *（BoxPool デフォルト）* | O(n) | O(log n) | **O(log n)** ポイント更新 |
| `RebuildSelectorBundleFactory` | O(n) | O(log n)† | O(n) 全再構築 |

† `AliasTableSelectorFactory` と組み合わせると O(1)。

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
    bundleFactory: new FenwickSelectorBundleFactory(),
);

// 極小 BoxPool（N ≤ 50）で O(1) pick が必要な場合
$pool = BoxPool::of(
    $items,
    fn(array $item) => $item['weight'],
    fn(array $item) => $item['stock'],
    bundleFactory: new RebuildSelectorBundleFactory(new AliasTableSelectorFactory()),
);
```

---

### 各セレクターの使い分け

---

#### PrefixSumSelector — WeightedPool のデフォルト

**メリット:** ビルドコストが低く、O(log n) の抽選速度はほぼすべてのユースケースで十分。  
**デメリット:** N が増えるにつれわずかに遅くなる（N=1000 でも < 0.4 µs）。

まずはこれを使ってください。`WeightedPool::of()` を毎リクエスト呼んでも構築コストは小規模 N で < 1 µs。

---

#### AliasTableSelector — 多 draw WeightedPool 向け O(1) 抽選

**メリット:** 真の O(1) 抽選 — N=50,000 でもスループットがほぼ横ばい。  
**デメリット:** 構築コストが PrefixSum の約 2.5 倍。十分な draw 回数で回収できる。

構築コストは次の draw 回数を超えると回収できます：

| アイテム数 | 損益分岐点（draw 回数） |
|-----------|----------------------|
|    50 | 約 36 回  |
|   100 | 約 63 回  |
|  1000 | 約 434 回 |

> リクエストごとにプールを再構築して数回しか引かない典型的なガチャでは、
> Alias はビルドコストを回収できません。`PrefixSumSelector` を使ってください。

> **N が大きい BoxPool には不向き。** 枯渇のたびに O(n) の全再構築（`update()` 非対応）が発生し、
> 大規模 N では合計コストが O(n²) になります。N ≤ 約50 の小規模プールでは許容範囲；
> N ≥ 500 では `FenwickSelectorBundleFactory`（デフォルト）を使ってください。

---

#### FenwickTreeSelector / FenwickSelectorBundleFactory — BoxPool のデフォルト

**メリット:** 枯渇時の更新が O(log n) — 全再構築なし。全 draw の合計コストが O(n²) から O(n log n) に改善。N=10,000+ にスケール。  
**デメリット:** イミュータブルプールでの pick は PrefixSum より若干遅い（Fenwick ツリー降下 vs ソート済み prefix sum の二分探索）。

`FenwickSelectorBundleFactory` は `FenwickTreeSelector` と `FenwickSelectorBuilder` を**同一インスタンス**でペアリングします。アイテムが枯渇すると `subtract()` がツリーに O(log n) の `update(index, 0)` を呼びます — オブジェクト生成も配列コピーも不要。

`RebuildSelectorBundleFactory` との全 draw 時間比較（µs/アイテム）：

| N    | RebuildBuilder（µs/アイテム） | FenwickBuilder（µs/アイテム） | 高速化 |
|------|-----------------------------:|-----------------------------:|-------:|
|  100 |                       ~3.0   |                      ~0.8    |  ~3.7倍 |
|  500 |                      ~10.4   |                      ~0.9    | ~12.0倍 |
| 1000 |                      ~19.6   |                      ~0.9    | ~21.4倍 |
| 5000 |                      ~94.2   |                      ~1.0    | ~91.0倍 |

> `WeightedPool` でのイミュータブル用途では、Fenwick の pick は PrefixSum より若干遅いため、
> `WeightedPool` には `PrefixSumSelectorFactory` か `AliasTableSelectorFactory` を使ってください。

---

#### RebuildSelectorBundleFactory — 極小 BoxPool 向け

**メリット:** `AliasTableSelectorFactory` を含む任意の `SelectorFactory` と組み合わせられる（枯渇間は O(1) 抽選）。  
**デメリット:** アイテム枯渇のたびに O(n) のセレクター全再構築が発生。N=500 では Fenwick の約12倍遅い。

N ≤ 約50 の極小プールで、かつ `AliasTableSelectorFactory` の O(1) 抽選が必要な場合のみ使用してください。
それ以外は `FenwickSelectorBundleFactory`（デフォルト）で問題ありません。

---

> **オーバーフロー制約:**
> - `PrefixSumSelector` / `FenwickTreeSelector`: 合計重み W が `PHP_INT_MAX` 以下であること
> - `AliasTableSelector`: さらに `(n+1) × W ≤ PHP_INT_MAX`（n = アイテム数；+1 は Vose のアルゴリズム中間値のヘッドルーム）が必要

### 速度比較 — 各アルゴリズムで 100万回抽選（重み: range(1, N)）

> PHP 8.4 / Darwin (macOS) で計測。結果はハードウェアと PHP の設定によって異なります。
> `php benchmark/bench.php` で自環境の結果を生成できます。

```
アイテム数   PrefixSum O(log n)   Fenwick O(log n)   Alias O(1)
       10         ~0.199 µs          ~0.211 µs      ~0.105 µs
       30         ~0.244 µs          ~0.262 µs      ~0.106 µs
       50         ~0.259 µs          ~0.291 µs      ~0.106 µs
      100         ~0.287 µs          ~0.323 µs      ~0.104 µs
      200         ~0.311 µs          ~0.355 µs      ~0.107 µs
      500         ~0.337 µs          ~0.393 µs      ~0.108 µs
     1000         ~0.364 µs          ~0.425 µs      ~0.107 µs
```

Alias の抽選スループットはアイテム数に関わらずほぼ一定（真の O(1)）。
PrefixSum と Fenwick は O(log n) で増加。イミュータブルプールでは PrefixSum の方が Fenwick より高速。
`php benchmark/compare.php` でアイテム数・draw 数別の損益分岐点と BoxPool スケーリングを確認できる。

### 精度比較 — N×500回抽選での最大偏差（均等重み、seed=99）

```
N      PrefixSum    Fenwick      Alias
   10    ~1.06%      ~1.06%     ~1.12%
   50    ~0.22%      ~0.22%     ~0.24%
  100    ~0.13%      ~0.13%     ~0.15%
  500    ~0.03%      ~0.03%     ~0.03%
 1000    ~0.02%      ~0.02%     ~0.02%
```

3つのアルゴリズムはすべて統計的に同等です。偏差はアルゴリズムの誤差ではなく、
純粋なサンプリングノイズです。どれも確率0のアイテムが抽選されることはありません。

---

## ベンチマーク結果

**WeightedPool — 100万回抽選（SSR=1%, SR=9%, R=90%）**

```
Item        Draws    Actual%  Expected%     Diff
SSR          9892     0.989%     1.000%   -0.011%
SR          90118     9.012%     9.000%   +0.012%
R          899990    89.999%    90.000%   -0.001%
```

**WeightedPool — 100アイテム（weight 1〜100）、100万回抽選**

```
最大偏差: ~0.037%（0.5ポイントを超えたアイテムはなし）
```

ベンチマークを実行する場合：

```bash
php benchmark/run.php
```

---

## 型安全性

PHPStan level 8 に完全対応。`@template T` によるジェネリクスで、`draw()` の戻り値の型が静的解析で追跡されます。

```php
use WeightedSample\Pool\WeightedPool;

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
