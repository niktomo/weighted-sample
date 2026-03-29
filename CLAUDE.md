# CLAUDE.md

## Project Overview

**weighted-sample** — 重み付き抽選ライブラリ。3つのプールモードをサポート。

- `WeightedPool` — 通常の重み付き抽選（プールは変化しない）
- `BoxPool` — ボックスガチャ（各アイテムに count を持ち、抽選するたびに消費。0になると除外）
- `DestructivePool` — 破壊的抽選（抽選したアイテムをプールから除外）

重みは Closure で抽出（デフォルト: `weight` キー or プロパティ）。
prefix sum + binary search で O(log n) 抽選。
Laravel 非依存。PHP ^8.2。

## Commands

```bash
composer install
vendor/bin/phpunit --no-coverage
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pint
```

## Architecture

```
src/
  Pool/
    WeightedPool.php       — 通常抽選（immutable）。PrefixSumIndex に委譲
    BoxPool.php            — Decorator。WeightedPool をラップし count を管理。消費後に再構築
    DestructivePool.php    — Decorator。WeightedPool をラップし draw 後に再構築
  Internal/
    PrefixSumIndex.php     — prefix sum + binary search エンジン。pick(float) でインデックス返却
  Randomizer/
    RandomizerInterface.php — next(): float [0,1) インターフェース
    DefaultRandomizer.php   — random_int ベースの実装
  Exception/
    EmptyPoolException.php
```

## 設計方針

- `BoxPool` / `DestructivePool` は `WeightedPool` の Decorator
  - 自身でアイテム列・count を保持
  - draw 後に内部 `WeightedPool` を再構築
  - `WeightedPool::drawWithIndex()` でインデックスを取得し、どのアイテムを除去するか判断
- `RandomizerInterface` を DI することでテストでスタブ差し替え可能
- `PrefixSumIndex::pick(float $rand)` は乱数を外から受け取る（テスタビリティのため）
