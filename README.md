# weighted-sample

[日本語版 README はこちら](README-ja.md)

Weighted random sampling for PHP 8.2+.
Two pool types cover the most common use cases: repeatable draws and box-gacha style limited inventory.

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
| `BoxPool` | Each item has a finite stock count; stock is decremented on draw | Yes |

All pools default to `SecureRandomizer` (`\Random\Engine\Secure`) — cryptographically safe with no configuration required.
Inject `SeededRandomizer(int $seed)` only for tests or reproducible simulations.

---

## WeightedPool

Immutable pool. `draw()` always selects from all items according to their weights.
Suitable for gacha, lotteries, and any scenario where items can be drawn repeatedly.

**Items can be any shape** — associative arrays, objects, or scalars.
The second argument is a closure that extracts the integer weight from each item:

```php
use WeightedSample\Pool\WeightedPool;

// Associative array — any key names work
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

The closure can reference any property or method:

```php
use WeightedSample\Pool\WeightedPool;

// Object with a method
$pool = WeightedPool::of($prizes, fn(Prize $p): int => $p->rarityWeight());

// Scalar — item IS the weight
$pool = WeightedPool::of([10, 30, 60], fn(int $w) => $w);
```

---

## BoxPool

Each item has a finite stock count. Drawing decrements the stock; when it reaches zero
the item is removed from the pool. Exhaustion throws `EmptyPoolException`.

**Use case:** Box gacha — a closed set of prizes where each prize appears a fixed number
of times. A single "item type" can be drawn multiple times until its stock runs out.

BoxPool requires **two** closures: one for the draw weight, one for the stock count.
Items can be any shape — just point the closures at the right fields:

```php
use WeightedSample\Pool\BoxPool;

// fn($item) => weight,  fn($item) => stock
$pool = BoxPool::of(
    $items,
    fn(array $item) => $item['weight'],  // draw probability
    fn(array $item) => $item['stock'],   // how many times it can be drawn
);
```

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

### drawMany()

`drawMany(int $count)` draws up to `$count` items in one call.
If the pool empties before `$count` is reached, it returns only what was drawn — no exception.

```php
use WeightedSample\Pool\BoxPool;

$pool = BoxPool::of($items, fn($i) => $i['weight'], fn($i) => $i['stock']);

$prizes = $pool->drawMany(10); // up to 10 items; fewer if pool runs dry
```

> **Note:** `BoxPool` is in-memory only. To resume across requests, persist the remaining
> stock counts and reconstruct the pool from those on the next request.

**How BoxPool manages stock internally:**
- On construction, BoxPool copies the items and records each stock count internally.
- Each `draw()` decrements the internal stock counter for the selected item.
- When a counter reaches zero, that item type is removed from the pool.
- The array you passed to `BoxPool::of()` is never touched.

---

## Item Filtering

Filtering is applied during `of()` construction — excluded items are never part of the pool
and cannot be drawn at runtime.

By default, items with `weight ≤ 0` are silently excluded. `BoxPool` additionally excludes
items with `stock ≤ 0`. If all items are excluded, `InvalidArgumentException` is thrown.

### Default: PositiveValueFilter (silent exclusion)

```php
use WeightedSample\Pool\WeightedPool;

// weight=0 items are excluded automatically — no exception thrown
$pool = WeightedPool::of($items, fn(array $item) => $item['weight']);
```

### StrictValueFilter (throw on invalid)

```php
use WeightedSample\Filter\StrictValueFilter;
use WeightedSample\Pool\WeightedPool;

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

### Custom filter for BoxPool

`BoxPool` requires `CountedItemFilterInterface`, which extends `ItemFilterInterface` with
an `acceptsWithCount()` method that receives the stock count as well as the weight.

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

**Note:** When `CompositeFilter` is used with `BoxPool`, inner filters that implement only
`ItemFilterInterface` (not `CountedItemFilterInterface`) will have `acceptsWithCount()` fall back
to `accepts()` — the stock count is ignored by those filters. If count-based exclusion is needed,
each inner filter must implement `CountedItemFilterInterface`.

### Exception types

| Exception | When thrown |
|---|---|
| `InvalidArgumentException` | Construction: `of()` received no valid items (all filtered out) |
| `EmptyPoolException` | Runtime: `draw()` called on an exhausted pool |

Construction failure and runtime exhaustion are different failure modes — `InvalidArgumentException` means bad input was given to `of()`, while `EmptyPoolException` means the pool ran out during use.

```php
use InvalidArgumentException;
use WeightedSample\Exception\EmptyPoolException;
use WeightedSample\Pool\WeightedPool;

// Construction-time: InvalidArgumentException when no items survive the filter.
// PositiveValueFilter (the default) excludes any item whose weight is 0.
try {
    $pool = WeightedPool::of($items, fn(array $item) => $item['weight']);
} catch (InvalidArgumentException $e) {
    // Bad input — every item was excluded. Check data or filter config.
    return;
}

// Runtime: EmptyPoolException when the pool is exhausted.
try {
    while (true) {
        $winner = $pool->draw();
    }
} catch (EmptyPoolException $e) {
    // Pool is exhausted — all items have been drawn.
}
```

---

## Streaming large item sets (iterable support)

All `of()` methods accept any `iterable` — not just arrays.
Pass a generator to avoid holding the source collection and the pool in memory at the same time.

```php
use WeightedSample\Pool\WeightedPool;

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
use WeightedSample\Pool\WeightedPool;
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

> **Warning:** `SeededRandomizer` uses `Mt19937`, which is **not** cryptographically secure.
> Before using it, confirm **all** of the following:
>
> - [ ] This code runs only in tests or offline simulations — never in a live request handler
> - [ ] The seed is not derived from `time()`, `microtime()`, or any timestamp (brute-forceable in < 1 s)
> - [ ] The seed is not shared across users or requests (same seed → identical sequence)
> - [ ] Users cannot observe enough outputs to reconstruct the state (624 consecutive 32-bit outputs suffice)
>
> If any box is unchecked, use `SecureRandomizer` (the default) instead.

**Safe seed derivation** (offline simulations only): derive the seed from a high-entropy source rather than a sequential counter or timestamp.

```php
// Bind the seed to a specific entity for per-entity reproducibility.
// The same userId + campaignId always produces the same draw sequence:
$seed = (int) hexdec(substr(hash('sha256', $userId . ':' . $campaignId), 0, 8));

// If you need a one-off reproducible sequence, generate the seed once, persist it,
// then reuse it to replay the exact same draws later:
$seed = unpack('N', random_bytes(4))[1]; // store this value — it is the key to replay
// Note: if replay is not needed, use SecureRandomizer (the default) instead.
```

---

## Customization

Every key behaviour is driven by an interface — swap any component via named parameters.
You can implement these interfaces yourself to plug in custom logic (feature-flag-aware filters,
database-backed randomizers, ML-weighted selectors, etc.).

| Parameter | Interface | Default | Why you'd change it |
|---|---|---|---|
| `randomizer:` | `RandomizerInterface` | `SecureRandomizer` (OS CSPRNG) | Use `SeededRandomizer` for tests / reproducible simulations |
| `filter:` | `ItemFilterInterface<T>` | `PositiveValueFilter` | Add custom exclusion rules; combine with `CompositeFilter` |
| `selectorFactory:` *(WeightedPool)* | `SelectorFactoryInterface` | `PrefixSumSelectorFactory` | Switch to `AliasTableSelectorFactory` for O(1) picks on large pools with many draws |
| `bundleFactory:` *(BoxPool)* | `SelectorBundleFactoryInterface` | `FenwickSelectorBundleFactory` | Swap builder strategy; `RebuildSelectorBundleFactory` for tiny pools (N ≤ 50) |

```php
use WeightedSample\Builder\FenwickSelectorBundleFactory;
use WeightedSample\Filter\StrictValueFilter;
use WeightedSample\Pool\BoxPool;
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Randomizer\SeededRandomizer;
use WeightedSample\Selector\AliasTableSelectorFactory;

// Change the randomizer (e.g. for tests)
$pool = WeightedPool::of($items, fn(array $i) => $i['weight'],
    randomizer: new SeededRandomizer(42),
);

// Change the filter
$pool = WeightedPool::of($items, fn(array $i) => $i['weight'],
    filter: new StrictValueFilter(),
);

// Change the selector algorithm (WeightedPool)
$pool = WeightedPool::of($items, fn(array $i) => $i['weight'],
    selectorFactory: new AliasTableSelectorFactory(),
);

// Change the builder strategy (BoxPool)
$pool = BoxPool::of($items, fn(array $i) => $i['weight'], fn(array $i) => $i['stock'],
    bundleFactory: new FenwickSelectorBundleFactory(), // default; shown for clarity
);
```

---

## Selector Algorithms

Three selection algorithms are available. Swap them via `selectorFactory` (WeightedPool) or
`bundleFactory` (BoxPool).

### Quick decision guide

**Start with the defaults.** Change only if you have measured a bottleneck.

| Pool | Item count (N) | Draws per pool lifetime | Recommendation |
|---|---|---|---|
| `WeightedPool` | any | few (< ~60) | `PrefixSumSelectorFactory` — **default**, best all-rounder |
| `WeightedPool` | any | many (≥ ~60) | `AliasTableSelectorFactory` — O(1) pick, cost amortised over many draws |
| `BoxPool` | any | — | `FenwickSelectorBundleFactory` — **default**, O(log n) update scales to 10,000+ items |
| `BoxPool` | ≤ 50 | — | `RebuildSelectorBundleFactory(new AliasTableSelectorFactory())` — O(1) pick between rare exhaustions |

> **High-load / large N?** `FenwickSelectorBundleFactory` is the right answer for `BoxPool`.
> Each item exhaustion costs O(log n) — not O(n) — so total draw cost stays O(n log n) even at N=10,000.
> See the [FenwickBuilder speedup table](#fenwicktreeselector--fenwickselectorbundlefactory) below.

### Complexity reference

| Selector / Factory | Build | Pick | Update (on exhaustion) |
|---|---|---|---|
| `PrefixSumSelectorFactory` *(default for WeightedPool)* | O(n) | O(log n) | O(n) full rebuild |
| `AliasTableSelectorFactory` | O(n) | **O(1)** | O(n) full rebuild |
| `FenwickSelectorBundleFactory` *(default for BoxPool)* | O(n) | O(log n) | **O(log n)** point update |
| `RebuildSelectorBundleFactory` | O(n) | O(log n)† | O(n) full rebuild |

† O(1) if paired with `AliasTableSelectorFactory`.

> `FenwickSelectorBundleFactory` pairs a `FenwickTreeSelector` with a `FenwickSelectorBuilder` that share the **same instance**. When an item is exhausted, `subtract()` calls `update(index, 0)` on the shared selector in O(log n) — no object creation, no array copy.

All algorithms use integer-only arithmetic — no float rounding errors.

```php
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Pool\BoxPool;
use WeightedSample\Selector\AliasTableSelectorFactory;
use WeightedSample\Builder\FenwickSelectorBundleFactory;
use WeightedSample\Builder\RebuildSelectorBundleFactory;

// WeightedPool with many repeated draws — use Alias for O(1) pick
$pool = WeightedPool::of(
    $items,
    fn(array $item) => $item['weight'],
    selectorFactory: new AliasTableSelectorFactory(),
);

// BoxPool with large N — FenwickSelectorBundleFactory is the default (O(n log n) total)
$pool = BoxPool::of(
    $items,
    fn(array $item) => $item['weight'],
    fn(array $item) => $item['stock'],
    bundleFactory: new FenwickSelectorBundleFactory(),
);

// BoxPool with AliasTable pick (O(1)) — use RebuildSelectorBundleFactory
$pool = BoxPool::of(
    $items,
    fn(array $item) => $item['weight'],
    fn(array $item) => $item['stock'],
    bundleFactory: new RebuildSelectorBundleFactory(new AliasTableSelectorFactory()),
);
```

---

### When to use each selector

---

#### PrefixSumSelector — default for WeightedPool

**Pros:** Low build cost, O(log n) pick, works for any WeightedPool use case.  
**Cons:** Pick grows slowly with N (still < 0.4 µs at N=1000).

Use this unless you have measured a need for faster picks. Rebuild on every `WeightedPool::of()` call is fine — build takes < 1 µs for small N.

---

#### AliasTableSelector — O(1) pick for WeightedPool with many draws

**Pros:** True O(1) pick regardless of N — throughput stays flat even at N=50,000.  
**Cons:** Build cost is ~2.5× higher than PrefixSum. Only pays off after enough draws.

Build cost amortises once you exceed the break-even draw count:

| Items | Break-even draws |
|-------|-----------------|
|    50 | ~36  |
|   100 | ~63  |
|  1000 | ~434 |

> If your pool is rebuilt per request (or per user session with only a handful of draws),
> `AliasTableSelector` never recoups its build cost — use `PrefixSumSelector` instead.

> **Not suitable for BoxPool with large N.** Each item exhaustion triggers a full O(n) rebuild
> (no incremental `update()` support), giving O(n²) total cost. For N ≤ ~50 it is acceptable;
> for N ≥ 500 use `FenwickSelectorBundleFactory` (the default).

---

#### FenwickTreeSelector / FenwickSelectorBundleFactory — default for BoxPool

**Pros:** O(log n) point update on exhaustion — no full rebuild. Total draw-all cost is O(n log n), not O(n²). Scales to N=10,000+.  
**Cons:** Pick is slightly slower than PrefixSum for immutable pools (Fenwick tree descent vs binary search).

`FenwickSelectorBundleFactory` pairs a `FenwickTreeSelector` with a `FenwickSelectorBuilder` that share the **same instance**. When an item is exhausted, `subtract()` calls `update(index, 0)` on the shared tree in O(log n) — no object creation, no array copy.

Measured speedup vs `RebuildSelectorBundleFactory` (total draw-all time, µs per item):

| N    | RebuildBuilder (µs/item) | FenwickBuilder (µs/item) | Speedup |
|------|-------------------------:|-------------------------:|--------:|
|  100 |                   ~3.0   |                   ~0.8   |   ~3.7x |
|  500 |                  ~10.4   |                   ~0.9   |  ~12.0x |
| 1000 |                  ~19.6   |                   ~0.9   |  ~21.4x |
| 5000 |                  ~94.2   |                   ~1.0   |  ~91.0x |

> For immutable `WeightedPool`, `FenwickTreeSelector` pick is slightly slower than `PrefixSumSelector` — use
> `PrefixSumSelector` or `AliasTableSelector` for `WeightedPool`.

---

#### RebuildSelectorBundleFactory — BoxPool with tiny N

**Pros:** Works with any `SelectorFactory` including `AliasTableSelectorFactory` (O(1) picks between exhaustions).  
**Cons:** Full O(n) selector rebuild on every item exhaustion. At N=500 that is ~12× slower than Fenwick.

Use only for very small pools (N ≤ ~50) where rebuild overhead is negligible,
and only if you specifically need O(1) picks from `AliasTableSelectorFactory`.
For everything else, `FenwickSelectorBundleFactory` (the default) is the right choice.

---

> **Overflow constraints:**
> - `PrefixSumSelector` / `FenwickTreeSelector`: total weight W must not exceed `PHP_INT_MAX`
> - `AliasTableSelector`: additionally requires `(n+1) × W ≤ PHP_INT_MAX` (n = item count; the +1 is headroom for Vose's algorithm intermediate values)

### Speed comparison — 1,000,000 picks each (range(1, N) weights)

> Measured on PHP 8.4 / Darwin (macOS). Results vary by hardware and PHP configuration.
> Run `php benchmark/bench.php` to generate results for your own environment.

```
Items   PrefixSum O(log n)   Fenwick O(log n)   Alias O(1)
    10         ~0.199 µs          ~0.211 µs      ~0.105 µs
    30         ~0.244 µs          ~0.262 µs      ~0.106 µs
    50         ~0.259 µs          ~0.291 µs      ~0.106 µs
   100         ~0.287 µs          ~0.323 µs      ~0.104 µs
   200         ~0.311 µs          ~0.355 µs      ~0.107 µs
   500         ~0.337 µs          ~0.393 µs      ~0.108 µs
  1000         ~0.364 µs          ~0.425 µs      ~0.107 µs
```

Alias throughput stays nearly constant regardless of item count (true O(1)).
PrefixSum and Fenwick grow as O(log n). For immutable pools, PrefixSum is faster than Fenwick.
Run `php benchmark/compare.php` for full break-even analysis and BoxPool scaling charts.

### Accuracy comparison — max deviation (N×500 draws, uniform weights, seed=99)

```
N      PrefixSum    Fenwick      Alias
   10    ~1.06%      ~1.06%     ~1.12%
   50    ~0.22%      ~0.22%     ~0.24%
  100    ~0.13%      ~0.13%     ~0.15%
  500    ~0.03%      ~0.03%     ~0.03%
 1000    ~0.02%      ~0.02%     ~0.02%
```

All three algorithms are statistically equivalent — deviations are pure sampling noise,
not algorithmic error. Neither algorithm can produce a result with zero probability.

---

## Benchmark results

> Measured on PHP 8.4.19 / Darwin (macOS). Run `php benchmark/run.php` for your own environment.

**WeightedPool — 1,000,000 draws (SSR=1%, SR=9%, R=90%)**

```
Item        Draws    Actual%  Expected%     Diff
SSR          9892     0.989%     1.000%   -0.011%
SR          90118     9.012%     9.000%   +0.012%
R          899990    89.999%    90.000%   -0.001%
```

**WeightedPool — 100 items (weight 1–100), 1,000,000 draws**

```
Max deviation: ~0.037%   (no item deviated by more than 0.5 percentage points)
```

Run the full benchmark:

```bash
php benchmark/run.php
```

---

## Type Safety

Full PHPStan level 8 compliance. The `@template T` generic lets static analysis track
the exact return type of `draw()` through the pool.

```php
use WeightedSample\Pool\WeightedPool;

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
