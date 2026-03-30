# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-03-31

### Added
- `AliasTableSelector` — Walker's alias method with O(1) pick using a single integer random call
  (`r = next(n×W)`, column = `r/W`, coinValue = `r%W`) and overflow guard for `n×W > PHP_INT_MAX`
- `SelectorInterface` — injectable selector strategy with `build(list<int> $weights): static` and `pick(RandomizerInterface): int`
- All `of()` methods now accept `iterable<T>` (generator / lazy cursor) in addition to arrays

### Changed
- `final readonly class` applied to all immutable value objects:
  `AliasTableSelector`, `PrefixSumSelector`, `PrefixSumIndex`, `SeededRandomizer`, `CompositeFilter`
- `BoxPool::draw()` now rebuilds the selector only when an item type is fully exhausted (count → 0),
  reducing rebuilds from O(total-draws) to O(distinct-item-types) — a significant performance improvement
  for pools with high stock counts

## [0.1.0] - 2025-03-29

### Added
- `WeightedPool` — immutable weighted pool for repeatable draws
- `DestructivePool` — removes each drawn item from the pool
- `BoxPool` — each item has a finite count; removed when count reaches zero
- `ItemFilterInterface` — injectable filter for weight/count validation
- `PositiveValueFilter` — default filter; silently excludes items with weight ≤ 0 or count ≤ 0
- `StrictValueFilter` — throws `InvalidArgumentException` for invalid weight/count
- `CompositeFilter` — chains multiple filters with short-circuit evaluation
- `SeededRandomizer` — deterministic randomizer; uses `Secure` engine by default, `Mt19937` with a seed
- `EmptyPoolException` — thrown when drawing from an empty pool or when all items are filtered out
- Prefix sum + binary search O(log n) selection with integer arithmetic only
