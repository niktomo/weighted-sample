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
use WeightedSample\Exception\EmptyPoolException;

$pool = DestructivePool::of(
    [
        ['id' => 1, 'weight' => 10],
        ['id' => 2, 'weight' => 30],
        ['id' => 3, 'weight' => 60],
    ],
    fn(array $item) => $item['weight'],
);

// Draw all items in weighted-random order — each item appears exactly once
$order = [];
while (! $pool->isEmpty()) {
    $order[] = $pool->draw()['id']; // e.g. [3, 2, 1] or [3, 1, 2] etc.
}

// The original array is never modified — DestructivePool works on an internal copy
```

---

## BoxPool

Each item has a finite stock count. Drawing decrements the stock; when it reaches zero
the item is removed from the pool. Exhaustion throws `EmptyPoolException`.

**Use case:** Box gacha — a closed set of prizes where each prize appears a fixed number
of times. Unlike `DestructivePool`, a single "item type" can be drawn multiple times
until its stock runs out.

```php
use WeightedSample\Pool\BoxPool;

// 10 prizes total: 1 Gold, 3 Silver, 6 Bronze
$pool = BoxPool::of(
    [
        ['name' => 'Gold',   'weight' => 10, 'stock' => 1],
        ['name' => 'Silver', 'weight' => 30, 'stock' => 3],
        ['name' => 'Bronze', 'weight' => 60, 'stock' => 6],
    ],
    fn(array $item) => $item['weight'],
    fn(array $item) => $item['stock'],   // <-- stock extractor
);

// Draw until the box is empty (exactly 10 draws)
while (! $pool->isEmpty()) {
    $item = $pool->draw(); // returns the drawn item; stock is managed internally
}

// The original array is never modified — BoxPool copies the data on construction
```

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

By default, items with `weight ≤ 0` (or `stock ≤ 0` for `BoxPool`) are silently excluded.
You can inject a custom filter to change this behavior.

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
use WeightedSample\Filter\PositiveValueFilter;

$enabledFilter = new class implements \WeightedSample\Filter\ItemFilterInterface {
    public function accepts(mixed $item, int $weight, ?int $count): bool
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

---

## Selector Algorithms

Two selection algorithms are available as `SelectorInterface` implementations.
You can swap them via the `selectorClass` named parameter on any pool.

| Selector | Build | Pick | Arithmetic |
|---|---|---|---|
| `PrefixSumSelector` (default) | O(n) | O(log n) | Integer only |
| `AliasTableSelector` | O(n) | O(1) | Integer pick (float only during build) |

```php
use WeightedSample\Pool\WeightedPool;
use WeightedSample\Selector\AliasTableSelector;

$pool = WeightedPool::of(
    $items,
    fn(array $item) => $item['weight'],
    selectorClass: AliasTableSelector::class, // swap to O(1) alias method
);
```

**When to use AliasTableSelector:**
The alias method picks in O(1) with a single integer random call — independent of item count.
Prefer it when `draw()` is called frequently on pools with many items (≥ ~50).

**When to use PrefixSumSelector (default):**
Integer-only arithmetic throughout. Slightly lower overhead per build, and O(log n) pick
is fast enough for small to medium item sets. Good default for most game gacha use cases.

### Speed comparison — 1,000,000 picks each

```
Items   PrefixSum O(log n)   Alias O(1)   Ratio
    10          ~194 ms        ~96 ms      2.0x faster
    50          ~253 ms        ~99 ms      2.6x faster
   100          ~270 ms        ~98 ms      2.8x faster
   200          ~284 ms       ~100 ms      2.8x faster
   500          ~316 ms       ~100 ms      3.2x faster
  1000          ~328 ms       ~102 ms      3.2x faster
```

Alias throughput stays constant at ~100 ms regardless of item count (true O(1)).

### Accuracy comparison — max deviation over 1,000,000 draws (seed=42)

```
Weight distribution       PrefixSum   Alias
1/3 each  [1,1,1]          ~0.057%   ~0.035%
1/7, 2/7, 4/7  [1,2,4]    ~0.061%   ~0.032%
1/5 each  [1,1,1,1,1]      ~0.048%   ~0.090%
```

Both algorithms are statistically equivalent — deviations are pure sampling noise,
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

---

## Requirements

- PHP 8.2+
- No runtime dependencies

## License

MIT
