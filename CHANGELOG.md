# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
