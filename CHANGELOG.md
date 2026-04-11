# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0] - 2026-04-11

### Added
- `SelectorFactoryInterface` — stateless factory used by `WeightedPool` to construct a `SelectorInterface` from weights
- `SelectorBundleFactoryInterface` — stateless factory used by `BoxPool` to construct a `SelectorBundle` (selector + builder pair)
- `SelectorBuilderInterface` — stateful builder (`subtract()`, `currentSelector()`, `totalWeight()`) used internally by `BoxPool`
- `SelectorBundle` — value object pairing a `SelectorInterface` with a `SelectorBuilderInterface`
- `FenwickSelectorBuilder` — O(log n) update builder backed by a shared `FenwickTreeSelector` instance
- `FenwickSelectorBundleFactory` — default `SelectorBundleFactoryInterface` for `BoxPool`; uses `FenwickSelectorBuilder`
- `RebuildSelectorBuilder` — O(n) rebuild builder; caches `totalWeight` in O(1) via difference updates
- `RebuildSelectorBundleFactory` — alternative `SelectorBundleFactoryInterface`; accepts any `SelectorFactoryInterface` (e.g. `AliasTableSelectorFactory`)
- `PrefixSumSelectorFactory`, `AliasTableSelectorFactory`, `FenwickTreeSelectorFactory` — `SelectorFactoryInterface` implementations
- `ExhaustiblePoolInterface::drawMany(int $count): list<T>` — draws up to `$count` items; stops early without exception if the pool empties; throws `\InvalidArgumentException` for negative `$count`
- `DestructivePool::drawMany()` — same semantics as `BoxPool::drawMany()`

### Changed
- **BREAKING** `SelectorInterface` — `static build(array $weights): static` removed; `pick()` is now the only method
- **BREAKING** `WeightedPool::of()` — `$selectorClass` (`class-string<SelectorInterface>`) replaced by `$selectorFactory` (`SelectorFactoryInterface`); default is `new PrefixSumSelectorFactory()`
- **BREAKING** `BoxPool::of()` — `$selectorClass` (`class-string<SelectorInterface>`) replaced by `$selectorBundleFactory` (`SelectorBundleFactoryInterface`); default is `new FenwickSelectorBundleFactory()`
- **BREAKING** `DestructivePool::of()` — `$selectorClass` (`class-string<SelectorInterface>`) replaced by `$selectorFactory` (`SelectorFactoryInterface`); default is `new PrefixSumSelectorFactory()`
- **BREAKING** `UpdatableSelectorInterface` removed from `WeightedSample\Selector` namespace; `FenwickTreeSelector` no longer implements it — use `FenwickSelectorBundleFactory` instead
- **BREAKING** `DestructivePool` marked `@deprecated`; use `BoxPool::of($items, $weightFn, fn($item) => 1)` as a drop-in replacement

### Removed
- **BREAKING** `WeightedSample\Selector\UpdatableSelectorInterface` — no longer needed; `FenwickSelectorBuilder` holds `FenwickTreeSelector` concretely

### Migration Guide (v1.x → v2.0.0)

```php
// v1.x
WeightedPool::of($items, $weightFn, selectorClass: AliasTableSelector::class);
BoxPool::of($items, $weightFn, $countFn, selectorClass: FenwickTreeSelector::class);

// v2.0.0
use WeightedSample\Selector\AliasTableSelectorFactory;
use WeightedSample\Builder\FenwickSelectorBundleFactory;

WeightedPool::of($items, $weightFn, selectorFactory: new AliasTableSelectorFactory());
BoxPool::of($items, $weightFn, $countFn, selectorBundleFactory: new FenwickSelectorBundleFactory());

// DestructivePool → BoxPool
// v1.x
DestructivePool::of($items, $weightFn);
// v2.0.0
BoxPool::of($items, $weightFn, fn($item) => 1);
```

## [1.1.0] - 2026-04-05

### Added
- `FenwickTreeSelector` — Fenwick tree (Binary Indexed Tree) selector with O(n) build, O(log n) pick, and **O(log n) update**
- `UpdatableSelectorInterface` — extends `SelectorInterface` with `update(int $index, int $newWeight): void` and `totalWeight(): int`; implemented by `FenwickTreeSelector`
- `DestructivePool` and `BoxPool` now detect `UpdatableSelectorInterface` and use `update(index, 0)` instead of full selector rebuild — reduces total draw-all cost from O(n²) to O(n log n) when `FenwickTreeSelector` is used
- `FenwickTreeSelector::build()` validates empty weights and non-positive weights (matching `PrefixSumSelector` and `AliasTableSelector` behavior)
- `FenwickTreeSelector::build()` overflow guard (`runningTotal > PHP_INT_MAX - weight`) throws `\OverflowException` before integer overflow can occur

### Changed
- **BREAKING** `$weightFn` parameter renamed to `$weightExtractor` in `WeightedPool::of()`, `DestructivePool::of()`, and `BoxPool::of()`
- **BREAKING** `$countFn` parameter renamed to `$countExtractor` in `BoxPool::of()`
  - Named argument callers must update: `weightFn:` → `weightExtractor:`, `countFn:` → `countExtractor:`

## [1.0.1] - 2026-04-05

### Fixed
- `AliasTableSelector::build()` now throws `InvalidArgumentException` for empty weights and weight ≤ 0 (matches `PrefixSumSelector` behavior)

### Changed
- `SelectorInterface` docblock corrected: pick uses integer-only arithmetic (not float)
- README: updated speed table to current benchmark values; added break-even guidance clarifying `PrefixSumSelector` as the right default for typical gacha use cases
- README: documented `CompositeFilter` fallback behavior for filters that do not implement `CountedItemFilterInterface`
- `benchmark/bench.php`, `benchmark/compare.php`: added build/pick/accuracy comparison with break-even heatmap

## [1.0.0] - 2026-03-31

### Added
- `SecureRandomizer` — cryptographically secure randomizer backed by `\Random\Engine\Secure`; now the default for all pools
- `CountedItemFilterInterface` — extends `ItemFilterInterface` with `acceptsWithCount(item, weight, count): bool` for `BoxPool`-specific filtering
- `AllItemsFilteredException` — extends `EmptyPoolException`; thrown at construction time when all items are excluded by the filter (distinguishes construction failure from runtime exhaustion)
- `@template T` generics on `ItemFilterInterface<T>` and `CountedItemFilterInterface<T>` for full PHPStan level 8 type tracking through filters
- `AliasTableSelector`: safety clamp `max(1, min(W, round(p × W)))` on threshold values — guarantees any item with weight > 0 is always reachable, preventing selection failure due to float rounding in Vose's algorithm
- `PrefixSumIndex`: overflow guard (`runningTotal > PHP_INT_MAX - weight`) throws `\OverflowException` before integer overflow can occur

### Changed
- **BREAKING** `SeededRandomizer` now requires an explicit `int $seed`; the optional `?int $seed = null` form (which silently fell back to `Secure` engine) is removed — use `SecureRandomizer` for seedless production use
- **BREAKING** All pool `of()` parameter order changed to `filter, selectorClass, randomizer` (nullable `randomizer` moved last)
- **BREAKING** All pool `of()` methods now accept `RandomizerInterface $randomizer = new SecureRandomizer()` (non-nullable) instead of `?RandomizerInterface $randomizer = null`
- **BREAKING** `BoxPool::of()` `$filter` parameter type changed from `ItemFilterInterface` to `CountedItemFilterInterface`
- **BREAKING** `EmptyPoolException` is no longer `final` to allow `AllItemsFilteredException` to extend it
- `PositiveValueFilter` and `StrictValueFilter` now implement `CountedItemFilterInterface` (backward compatible — they already handled count logic)
- `CompositeFilter` now implements `CountedItemFilterInterface`; delegates to `acceptsWithCount()` when an inner filter implements it, falls back to `accepts()` otherwise

### Removed
- **BREAKING** `SeededRandomizer::__construct(?int $seed = null)` — seedless construction removed; replaced by `SecureRandomizer`

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
