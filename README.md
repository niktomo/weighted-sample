# weighted-sample

[日本語版 README はこちら](README-ja.md)

Weighted random sampling for PHP 8.2+.
Three pool types cover the most common use cases: repeatable draws, destructive draws, and box-gacha style limited inventory.

[![CI](https://github.com/niktomo/weighted-sample/actions/workflows/ci.yml/badge.svg)](https://github.com/niktomo/weighted-sample/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://www.php.net)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

---

## Installation

```bash
composer require niktomo/weighted-sample
```

---

## Pool Types

| Class | Draw behavior | Exhaustible |
|---|---|---|
| `WeightedPool` | Always draws from the full set | No |
| `DestructivePool` | Each item can be drawn at most once; drawn items are removed | Yes |
| `BoxPool` | Each item has a finite stock count; stock is decremented on draw | Yes |

All pools default to `SecureRandomizer` (`\Random\Engine\Secure`) — cryptographically safe with no configuration required.
Inject `SeededRandomizer(int $seed)` only for tests or reproducible simulations.

---

## WeightedPool

Immutable pool. `draw()` always selects from all items according to their weights.
Suitable for gacha, lotteries, and any scenario where items can be drawn repeatedly.

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

$result = $pool->draw(); // returns one item — 90% chance of R, 9% SR, 1% SSR
```

---

## DestructivePool

Each drawn item is permanently removed from the pool.
Once all items have been drawn, further calls to `draw()` throw `EmptyPoolException`.

**Use case:** Weighted shuffle — all items will eventually be drawn, but higher-weight items
tend to appear earlier in the sequence.

```php
use WeightedSample\Pool\DestructivePool;

$pool = DestructivePool::of(
    [
        ['id' => 1, 'weight' => 10],
        ['id' => 2, 'weight' => 30],
        ['id' => 3, 'weight' => 60],
    ],
    fn(array $item) => $item['weight'],
);

// Single draw — picks one item, removes it from the pool
$winner = $pool->draw();

// Or draw all in weighted-random order — each item appears exactly once
$order = [];
while (! $pool->isEmpty()) {
    $order[] = $pool->draw()['id']; // e.g. [3, 2, 1] or [3, 1, 2] etc.
}

// The original array is never modified — DestructivePool works on an internal copy
```

> **Note:** `DestructivePool` is in-memory only. To resume across requests, persist which items
> remain and reconstruct the pool from those on the next request.

---

## BoxPool

Each item has a finite stock count. Drawing decrements the stock; when it reaches zero
the item is removed from the pool. Exhaustion throws `EmptyPoolException`.

**Use case:** Box gacha — a closed set of prizes where each prize appears a fixed number
of times. Unlike `DestructivePool`, a single "item type" can be drawn multiple times
until its stock runs out.

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

// The box holds 55 prizes. A player draws 50 at a time, 3 rounds.
//
// Round 1 (draws  1– 50): 50 succeed — 5 remain in the current box
// Round 2 (draws 51– 55): box empties after 5 draws — a fresh box is opened (55 prizes)
//          (draws 56–100): 45 draws from the new box — 10 remain
// Round 3 (draws 101–110): box empties after 10 draws — a fresh box is opened (55 prizes)
//          (draws 111–150): 40 draws from the new box — 15 remain

$pool = $newBox();

for ($round = 1; $round <= 3; $round++) {
    $drawn = [];
    for ($i = 0; $i < 50; $i++) {
        if ($pool->isEmpty()) {
            $pool = $newBox(); // open a fresh box and continue drawing
        }
        $drawn[] = $pool->draw();
    }
    // process $drawn for this round
}
```

> **Note:** `BoxPool` is in-memory only. To resume across requests, persist the remaining
> stock counts and reconstruct the pool from those on the next request.

**How BoxPool manages stock internally:**
- On construction, BoxPool copies the items and records each stock count internally.
- Each `draw()` decrements the internal stock counter for the selected item.
- When a counter reaches zero, that item type is removed from the pool.
- The array you passed to `BoxPool::of()` is never touched.

---

## DestructivePool vs BoxPool

| | `DestructivePool` | `BoxPool` |
|---|---|---|
| Item instances | Each item is unique (no duplicates) | An item type can appear multiple times |
| On draw | Item is removed immediately | Stock is decremented; removed at zero |
| Total draws | Always equals item count | Equals sum of all stock counts |
| Typical use | Raffle, shuffled playlist | Box gacha, limited inventory |

---

## Item Filtering

Filtering is applied during `of()` construction — excluded items are never part of the pool
and cannot be drawn at runtime.

By default, items with `weight ≤ 0` are silently excluded. `BoxPool` additionally excludes
items with `stock ≤ 0`. If all items are excluded, `AllItemsFilteredException` is thrown.

### Default: PositiveValueFilter (silent exclusion)

```php
// weight=0 items are excluded automatically — no exception thrown
$pool = WeightedPool::of($items, fn(array $item) => $item['weight']);
```

### StrictValueFilter (throw on invalid)

```php
use WeightedSample\Filter\StrictValueFilter;

// Throws InvalidArgumentException if any item has weight ≤ 0
$pool = WeightedPool::of(
    $items,
    fn(array $item) => $item['weight'],
    filter: new StrictValueFilter(),
);
```

### CompositeFilter (combine multiple filters)

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

### Custom filter for BoxPool

`BoxPool` requires `CountedItemFilterInterface`, which extends `ItemFilterInterface` with
an `acceptsWithCount()` method that receives the stock count as well as the weight.

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

**Note:** When `CompositeFilter` is used with `BoxPool`, inner filters that implement only
`ItemFilterInterface` (not `CountedItemFilterInterface`) will have `acceptsWithCount()` fall back
to `accepts()` — the stock count is ignored by those filters. If count-based exclusion is needed,
each inner filter must implement `CountedItemFilterInterface`.

### Exception types

| Exception | When thrown |
|---|---|
| `AllItemsFilteredException` | Construction: all items were excluded by the filter |
| `EmptyPoolException` | Runtime: `draw()` called on an exhausted pool |

`AllItemsFilteredException` extends `EmptyPoolException` — existing `catch (EmptyPoolException)` blocks continue to work.

---

## Streaming large item sets (iterable support)

All `of()` methods accept any `iterable` — not just arrays.
Pass a generator to avoid holding the source collection and the pool in memory at the same time.

```php
// Read from a database cursor without buffering all rows into an array first
function itemsFromDb(\PDOStatement $stmt): \Generator
{
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        yield $row;
    }
}

$pool = WeightedPool::of(itemsFromDb($stmt), fn(array $row) => $row['weight']);
```

Note: the pool itself stores all accepted items internally, so peak memory
after construction is proportional to the number of accepted items.

---

## Seeded Randomizer

Inject a seeded randomizer for deterministic results — useful in tests and simulations.

```php
use WeightedSample\Randomizer\SeededRandomizer;

$pool = WeightedPool::of(
    $items,
    fn(array $item) => $item['weight'],
    randomizer: new SeededRandomizer(42), // fixed seed
);

// Always produces the same sequence for seed=42
$result = $pool->draw();
```

The default (no seed) uses `\Random\Engine\Secure` for cryptographically safe randomness.

> **Note:** `SeededRandomizer` with a seed uses `Mt19937`, which is **not** cryptographically
> secure. Use a fixed seed only for testing or deterministic simulations, never for production draws.

---

## Selector Algorithms

Three selection algorithms are available as `SelectorInterface` implementations.
You can swap them via the `selectorClass` named parameter on any pool.

| Selector | Build | Pick | Update | Best for |
|---|---|---|---|---|
| `PrefixSumSelector` (default) | O(n) | O(log n) | O(n) rebuild | WeightedPool, small DestructivePool/BoxPool |
| `AliasTableSelector` | O(n) | O(1) | O(n) rebuild | WeightedPool with many draws |
| `FenwickTreeSelector` | O(n) | O(log n) | **O(log n)** | DestructivePool / BoxPool with N ≥ 500 |

All three use integer-only arithmetic — no float rounding errors.

```php
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Pool\DestructivePool;
use WeightedSample\Selector\AliasTableSelector;
use WeightedSample\Selector\FenwickTreeSelector;

// WeightedPool with many repeated draws — use Alias for O(1) pick
$pool = WeightedPool::of(
    $items,
    fn(array $item) => $item['weight'],
    selectorClass: AliasTableSelector::class,
);

// DestructivePool with large N — use FenwickTree for O(n log n) total vs O(n²)
$pool = DestructivePool::of(
    $items,
    fn(array $item) => $item['weight'],
    selectorClass: FenwickTreeSelector::class,
);
```

---

### When to use each selector

**PrefixSumSelector (default)**
The right choice for almost all gacha and lottery use cases: low build overhead, and
O(log n) pick is fast enough even for hundreds of items with typical draw counts.

**AliasTableSelector**
Build cost is ~2.8× higher than PrefixSum. That upfront cost only pays off when
`draw()` is called many times on the **same pool instance** (i.e. a long-lived `WeightedPool`).

Approximate break-even draw counts:

| Items | Break-even draws |
|-------|-----------------|
|    50 | ~40  |
|   100 | ~65  |
|  1000 | ~440 |

If your pool is rebuilt per request or per user session with only a handful of draws,
`AliasTableSelector` never recoups its build cost.

> **Note:** `AliasTableSelector` is not efficient with `DestructivePool` or `BoxPool`.
> These pools mutate on every draw — without `update()` support, Alias falls back to
> a full O(n) rebuild per draw, giving O(n²) total. Use `FenwickTreeSelector` instead.

**FenwickTreeSelector**
Implements `UpdatableSelectorInterface`, which adds an `update(int $index, int $newWeight): void`
method. `DestructivePool` and `BoxPool` use this to exclude drawn items in O(log n) rather than
rebuilding the entire selector — reducing total draw-all cost from O(n²) to O(n log n).

| N    | PrefixSum (µs/item) | Fenwick (µs/item) | Speedup |
|------|--------------------:|------------------:|--------:|
|  100 |              ~1.6   |             ~0.7  |    ~2x  |
|  500 |              ~4.9   |             ~0.7  |    ~7x  |
| 1000 |              ~9.3   |             ~0.8  |   ~12x  |
| 5000 |             ~45.0   |             ~0.9  |   ~48x  |

Use `FenwickTreeSelector` for `DestructivePool` / `BoxPool` when N ≥ 500.
For immutable `WeightedPool`, `FenwickTreeSelector` pick is slightly slower than `PrefixSumSelector`
(Fenwick tree descent has more overhead than binary search on a sorted prefix-sum array) — use
`PrefixSumSelector` or `AliasTableSelector` for immutable pools.

> **Overflow constraints:**
> - `PrefixSumSelector` / `FenwickTreeSelector`: total weight W must not exceed `PHP_INT_MAX`
> - `AliasTableSelector`: additionally requires `n × W ≤ PHP_INT_MAX` (n = item count)

### Speed comparison — 200,000 picks each (range(1, N) weights)

```
Items   PrefixSum O(log n)   Fenwick O(log n)   Alias O(1)
    10         ~0.200 µs          ~0.209 µs      ~0.104 µs
    50         ~0.260 µs          ~0.289 µs      ~0.107 µs
   100         ~0.287 µs          ~0.321 µs      ~0.106 µs
   500         ~0.332 µs          ~0.385 µs      ~0.105 µs
  1000         ~0.355 µs          ~0.415 µs      ~0.107 µs
  5000         ~0.396 µs          ~0.516 µs      ~0.112 µs
 50000         ~0.480 µs          ~0.660 µs      ~0.115 µs
```

Alias throughput stays nearly constant regardless of item count (true O(1)).
PrefixSum and Fenwick grow as O(log n). For immutable pools, PrefixSum is faster than Fenwick.
Run `php benchmark/compare.php` for full break-even analysis and DestructivePool scaling charts.

### Accuracy comparison — max deviation over 100,000 draws (equal weights, seed=42)

```
N      PrefixSum    Fenwick      Alias
   10    ~1.06%      ~1.06%     ~1.12%
  100    ~0.13%      ~0.13%     ~0.15%
 1000    ~0.02%      ~0.02%     ~0.02%
```

All three algorithms are statistically equivalent — deviations are pure sampling noise,
not algorithmic error. Neither algorithm can produce a result with zero probability.

---

## Benchmark results

**WeightedPool — 1,000,000 draws (SSR=1%, SR=9%, R=90%)**

```
Item        Draws    Actual%  Expected%     Diff
SSR         10034     1.003%     1.000%   +0.003%
SR          90107     9.011%     9.000%   +0.011%
R          899859    89.986%    90.000%   -0.014%
```

**WeightedPool — 100 items (weight 1–100), 1,000,000 draws**

```
Max deviation: ~0.024%   (no item deviated by more than 0.5 percentage points)
```

Run the full benchmark:

```bash
docker compose run --rm benchmark
# or without Docker:
php benchmark/run.php
```

---

## Type Safety

Full PHPStan level 8 compliance. The `@template T` generic lets static analysis track
the exact return type of `draw()` through the pool.

```php
/** @var WeightedPool<array{name: string, weight: int}> $pool */
$pool = WeightedPool::of($items, fn(array $item) => $item['weight']);

$item = $pool->draw(); // PHPStan infers array{name: string, weight: int}
```

Filter interfaces are also generic — `ItemFilterInterface<T>` and `CountedItemFilterInterface<T>`
carry the item type through static analysis.

---

## Requirements

- PHP 8.2+
- No runtime dependencies

## License

MIT
