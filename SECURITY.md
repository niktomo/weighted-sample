# Security Policy

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| 2.0.x   | ✅ Active          |
| 1.1.x   | ⚠️ Security fixes only |
| < 1.1   | ❌ End of life     |

## Patching Policy

- **Critical / High vulnerabilities** — patch released within 7 days.
- **Medium vulnerabilities** — patch released in the next minor release.
- **Low / Informational** — addressed in the next scheduled release.

Patches are released as tagged versions following Semantic Versioning.
Credit is given in the release notes unless you prefer to remain anonymous.

## Reporting a Vulnerability

Please **do not** report security vulnerabilities via public GitHub Issues.

Instead, open a [GitHub Security Advisory](https://github.com/niktomo/weighted-sample/security/advisories/new) to report privately.

Include:

- A description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

You will receive a response within 7 days.

## Security Considerations for Users

- **Randomizer**: The default `SecureRandomizer` uses OS-level CSPRNG via `\Random\Engine\Secure`:
  - **Linux**: `getrandom()` syscall (non-blocking after initial kernel boot; falls back to `/dev/urandom`, which unlike `/dev/random` never blocks once the entropy pool is initialised)
  - **macOS**: `arc4random_buf()` (non-blocking, automatically seeded by the kernel)
  - **Windows**: `BCryptGenRandom` (non-blocking)
  - All platforms: safe for production draws including lotteries and gacha.
- **`SeededRandomizer`**: Uses `\Random\Engine\Mt19937` — deterministic, **not cryptographically secure**. Intended for testing and benchmarks only; never use in production draws where unpredictability matters.
- **Integer overflow**: Weight sums are validated against `PHP_INT_MAX` at construction; `OverflowException` is thrown before any draw can occur.

## Known Attack Vectors When Using `SeededRandomizer` in Production

> These risks apply **only if** `SeededRandomizer` is misused in production. The default `SecureRandomizer` is not affected.

### 1. State Prediction (Sequence Attack)

Mt19937 has a 19,937-bit internal state. After observing **624 consecutive 32-bit outputs**, an attacker can reconstruct the full internal state and predict all future outputs exactly. In a weighted-sampling context this means an attacker who can observe enough draw results can predict every subsequent outcome, breaking any fairness guarantee.

**Mitigation:** Use `SecureRandomizer` (the default). Never pass `SeededRandomizer` to a production pool.

### 2. Seed Enumeration

Mt19937 seeds are constrained to `[0, 4 294 967 295]` (2^32 ≈ 4.3 billion values).
When the seed is derived from a timestamp, the search space collapses dramatically:

| Seed source | Candidates per 1-hour window | Brute-force time (10M seeds/s) |
|---|---:|---:|
| `time()` | 3,600 | < 1 ms |
| `(int)(microtime(true) * 1_000)` | 3,600,000 | < 1 s |
| `(int)(microtime(true) * 1_000_000)` | 3,600,000,000 (full 32-bit range) | ~6 min |

Modern CPUs can test tens of millions of Mt19937 seeds per second in a single thread;
GPUs can test billions. An attacker who observes as few as **two consecutive outputs** and
knows the approximate seed time can recover the exact seed offline and predict all future draws.

**Mitigation:** Use `SecureRandomizer`. If you must persist a seed for audit/replay purposes, treat it as a secret credential and never derive it from a timestamp.

### 3. Seed Reuse

Two pools created with the same seed produce identical draw sequences. In systems where multiple users share an application process, reusing or predictably incrementing seeds lets one user predict another's outcomes.

**Mitigation:** Use `SecureRandomizer`. Each pool gets a fresh CSPRNG state automatically.

### 4. Bias via Modulo (Mitigated by Design)

Naive `rand() % N` sampling introduces a modulo bias when the range is not a power of two. This library uses PHP's `\Random\Randomizer::getInt()` internally, which eliminates modulo bias via rejection sampling. The Alias and PrefixSum selectors also use integer-only arithmetic, removing any floating-point rounding bias.

No action required; this is handled automatically.
