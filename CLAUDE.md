# CLAUDE.md

## Project Overview

**weighted-sample** — 重み付き抽選ライブラリ。3つのプールモードをサポート。

- `WeightedPool` — 通常の重み付き抽選（immutable、プールは変化しない）
- `DestructivePool` — 破壊的抽選（抽選したアイテムをプールから除外）
- `BoxPool` — ボックスガチャ（各アイテムに count を持ち、抽選するたびに消費。0になると除外）

重みは Closure で抽出。デフォルトは prefix sum + binary search で O(log n) 抽選。
Walker's Alias Method による O(1) セレクタも提供。
Laravel 非依存。PHP ^8.2。

## Commands

```bash
composer install
vendor/bin/phpunit --no-coverage
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pint
php benchmark/bench.php   # ビルド・draw・トータル時間を計測
php benchmark/run.php     # 統計精度の検証
```

## File Layout

```
weighted-sample/
├── src/
│   ├── Pool/
│   │   ├── PoolInterface.php              — draw(): T
│   │   ├── ExhaustiblePoolInterface.php   — draw(): T + isEmpty(): bool
│   │   ├── WeightedPool.php               — immutable。毎回フルセットから抽選
│   │   ├── DestructivePool.php            — draw() 後にアイテムを除外。セレクタを再構築
│   │   └── BoxPool.php                    — draw() 後に count を減算。0 になったら除外
│   ├── Filter/
│   │   ├── ItemFilterInterface.php        — accepts(item, weight): bool
│   │   ├── CountedItemFilterInterface.php — acceptsWithCount(item, weight, count): bool
│   │   ├── PositiveValueFilter.php        — デフォルト。weight/count ≤ 0 を除外（例外なし）
│   │   ├── StrictValueFilter.php          — weight/count ≤ 0 で InvalidArgumentException
│   │   └── CompositeFilter.php            — 複数フィルターの合成（AND 短絡評価）
│   ├── Selector/
│   │   ├── SelectorInterface.php          — build(weights): static / pick(randomizer): int
│   │   ├── PrefixSumSelector.php          — O(n) build / O(log n) pick。整数演算のみ
│   │   └── AliasTableSelector.php         — O(n) build / O(1) pick。Walker's Alias Method
│   ├── Randomizer/
│   │   ├── RandomizerInterface.php        — next(int $max): int — [0, max) の整数を返す
│   │   ├── SecureRandomizer.php           — CSPRNG（\Random\Engine\Secure）。本番デフォルト
│   │   └── SeededRandomizer.php           — Mt19937 + seed。テスト・再現用。本番不可
│   ├── Internal/
│   │   └── PrefixSumIndex.php             — prefix sum 配列 + binary search エンジン（internal）
│   └── Exception/
│       ├── EmptyPoolException.php         — draw() 時にプールが空（runtime）
│       └── AllItemsFilteredException.php  — 構築時に全アイテムがフィルタで除外（extends EmptyPoolException）
├── tests/
│   ├── Unit/
│   │   ├── Pool/                          — WeightedPoolTest, DestructivePoolTest, BoxPoolTest
│   │   ├── Filter/                        — PositiveValueFilterTest, StrictValueFilterTest, CompositeFilterTest
│   │   ├── Selector/                      — PrefixSumSelectorTest, AliasTableSelectorTest
│   │   ├── Randomizer/                    — SecureRandomizerTest, SeededRandomizerTest
│   │   ├── Internal/                      — PrefixSumIndexTest
│   │   └── Exception/                     — EmptyPoolExceptionTest, AllItemsFilteredExceptionTest
│   └── Feature/
│       ├── WeightedPoolDrawTest.php        — ビジネスシナリオ: 抽選分布・immutability
│       ├── DestructivePoolDrawTest.php     — ビジネスシナリオ: 一意抽選・枯渇
│       └── BoxPoolDrawTest.php             — ビジネスシナリオ: ストック消費・再重み付け
├── benchmark/
│   ├── bench.php                          — build / draw / total の時間計測（新設）
│   └── run.php                            — 統計精度・セレクタ速度比較
└── rules/
    └── coding-rule.md                     — 引数命名など本プロジェクト固有のコーディング規約
```

## 設計方針

- 各プールは `SelectorInterface` と `RandomizerInterface` を直接保持（継承なし）
- `ItemFilterInterface` を DI して weight/count のバリデーション挙動をカスタマイズ可能
- `RandomizerInterface` を DI してテストでスタブ差し替え可能（SeededRandomizer で再現性確保）
- `@template T` で型安全なジェネリクスを実現（PHPStan level 8）
- float 不使用（整数演算のみ）。AliasTableSelector は build 時のみ float を内部使用し、pick は純整数演算
- `of()` ファクトリ内で `$weightExtractor` / `$countExtractor` はアイテムごとに **1回だけ** 呼ぶ
  - フィルタリング時にキャッシュし、セレクタ構築に再利用する

## コーディング規約

詳細は `rules/coding-rule.md` を参照。要点：

- 引数名は振る舞い・役割を正確に表す。`Fn` / `Cb` などの型サフィックスは使わない
  - 例: `$weightFn` → `$weightExtractor`、`$countFn` → `$countExtractor`
  - ※ `$weightFn` / `$countFn` のリネームは named args 破壊的変更のため **v1.1.0 で対応**
- 略語を避ける（`$idx` → `$selectedIndex`、`$rand` → `$randomizer`）
