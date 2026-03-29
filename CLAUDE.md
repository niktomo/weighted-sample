# CLAUDE.md

## Project Overview

**weighted-sample** — 重み付き抽選ライブラリ。3つのプールモードをサポート。

- `WeightedPool` — 通常の重み付き抽選（immutable、プールは変化しない）
- `DestructivePool` — 破壊的抽選（抽選したアイテムをプールから除外）
- `BoxPool` — ボックスガチャ（各アイテムに count を持ち、抽選するたびに消費。0になると除外）

重みは Closure で抽出。prefix sum + binary search で O(log n) 抽選。
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
    PoolInterface.php           — draw(): T
    ExhaustiblePoolInterface.php — draw(): T + isEmpty(): bool
    WeightedPool.php            — immutable。PrefixSumIndex に委譲
    DestructivePool.php         — draw() 後にアイテムを除外。PrefixSumIndex を再構築
    BoxPool.php                 — draw() 後に count を減算。0 になったら除外
  Filter/
    ItemFilterInterface.php     — accepts(item, weight, ?count): bool
    PositiveValueFilter.php     — デフォルト。weight/count ≤ 0 を除外（例外なし）
    StrictValueFilter.php       — weight/count ≤ 0 で InvalidArgumentException
    CompositeFilter.php         — 複数フィルターの合成（短絡評価）
  Internal/
    PrefixSumIndex.php          — prefix sum + binary search エンジン。pick(int): int
  Randomizer/
    RandomizerInterface.php     — next(int $max): int — [0, max) の整数を返す
    SeededRandomizer.php        — seed なし → Secure エンジン、seed あり → Mt19937
  Exception/
    EmptyPoolException.php      — プールが空のとき、またはフィルター後に空になったとき
```

## 設計方針

- 各プールは `PrefixSumIndex` と `RandomizerInterface` を直接保持（継承なし）
- `ItemFilterInterface` を DI して weight/count のバリデーション挙動をカスタマイズ可能
- `RandomizerInterface` を DI してテストでスタブ差し替え可能（SeededRandomizer で再現性確保）
- `@template T` で型安全なジェネリクスを実現（PHPStan level 8）
- float 不使用。整数演算のみで重み付き選択を実現
