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
| `DestructivePool` | Drawn item is removed | Yes |
| `BoxPool` | Each item has a count; removed when count reaches 0 | Yes |

---

## WeightedPool

Immutable pool. `draw()` always selects from all items according to their weights.
Suitable for gacha, lotteries, and any scenario where items can be drawn repeatedly.

```php
use WeightedSample\Pool\WeightedPool;

$pool = WeightedPool::of(
    [
        ['name' => 'SSR', 'weight' => 5],
        ['name' => 'SR',  'weight' => 15],
        ['name' => 'R',   'weight' => 80],
    ],
    fn($item) => $item['weight'],
);

$result = $pool->draw(); // ['name' => 'R', 'weight' => 80]  (most likely)
```

---

## DestructivePool

Each drawn item is removed from the pool. Raises `EmptyPoolException` when exhausted.
Suitable for raffles, shuffled draws, and scenarios where each item can only be drawn once.

```php
use WeightedSample\Pool\DestructivePool;
use WeightedSample\Exception\EmptyPoolException;

$pool = DestructivePool::of(
    [
        ['id' => 1, 'weight' => 10],
        ['id' => 2, 'weight' => 30],
        ['id' => 3, 'weight' => 60],
    ],
    fn($item) => $item['weight'],
);

while (! $pool->isEmpty()) {
    $item = $pool->draw(); // each item drawn exactly once
}
```

---

## BoxPool

Each item has a finite count. Drawing decrements the count; the item is removed when count reaches 0.
Suitable for box-gacha, limited stock, and prize pools with fixed quantities.

```php
use WeightedSample\Pool\BoxPool;

$pool = BoxPool::of(
    [
        ['name' => 'Gold',   'weight' => 10, 'stock' => 1],
        ['name' => 'Silver', 'weight' => 30, 'stock' => 3],
        ['name' => 'Bronze', 'weight' => 60, 'stock' => 6],
    ],
    fn($item) => $item['weight'],
    fn($item) => $item['stock'],
);

while (! $pool->isEmpty()) {
    $item = $pool->draw(); // up to 10 draws total (1 + 3 + 6)
}
```

---

## Item Filtering

By default, items with `weight ≤ 0` (or `count ≤ 0` for `BoxPool`) are silently excluded.
You can inject a custom filter to change this behavior.

### Default: PositiveValueFilter (silent exclusion)

```php
use WeightedSample\Filter\PositiveValueFilter;

// weight=0 items are excluded automatically — no exception thrown
$pool = WeightedPool::of($items, fn($i) => $i['weight']);
```

### StrictValueFilter (throw on invalid)

```php
use WeightedSample\Filter\StrictValueFilter;

// Throws InvalidArgumentException if any item has weight ≤ 0
$pool = WeightedPool::of(
    $items,
    fn($i) => $i['weight'],
    filter: new StrictValueFilter(),
);
```

### CompositeFilter (combine multiple filters)

```php
use WeightedSample\Filter\CompositeFilter;
use WeightedSample\Filter\PositiveValueFilter;

$myFilter = new class implements \WeightedSample\Filter\ItemFilterInterface {
    public function accepts(mixed $item, int $weight, ?int $count): bool
    {
        return $item['enabled'] === true;
    }
};

$pool = WeightedPool::of(
    $items,
    fn($i) => $i['weight'],
    filter: new CompositeFilter([new PositiveValueFilter(), $myFilter]),
);
```

---

## Seeded Randomizer

Inject a seeded randomizer for deterministic results — useful in tests and simulations.

```php
use WeightedSample\Randomizer\SeededRandomizer;

$pool = WeightedPool::of(
    $items,
    fn($i) => $i['weight'],
    randomizer: new SeededRandomizer(42), // fixed seed
);

// Always produces the same sequence for seed=42
$result = $pool->draw();
```

The default (no seed) uses `\Random\Engine\Secure` for cryptographically safe randomness.

---

## Weight Distribution

All selection is performed using **prefix sum + binary search** (O(log n)) with integer arithmetic only — no floating-point rounding.

### Benchmark results

**WeightedPool — 1,000,000 draws (SSR=1%, SR=9%, R=90%)**

```
Item        Draws    Actual%  Expected%     Diff
SSR         10050     1.005%     1.000%   +0.005%
SR          90058     9.006%     9.000%   +0.006%
R          899892    89.989%    90.000%   -0.011%
```

**WeightedPool — 100 items (weight 1–100), 1,000,000 draws**

```
Item        Draws    Actual%  Expected%     Diff
w1            199     0.020%     0.020%   +0.000%
w2            377     0.038%     0.040%   -0.002%
...
w100        20049     2.005%     1.980%   +0.025%
Max deviation: 0.0264%
```

Run the full benchmark:

```bash
docker compose run --rm benchmark
# or without Docker:
php benchmark/run.php
```

---

## Requirements

- PHP 8.2+
- No runtime dependencies

## License

MIT
