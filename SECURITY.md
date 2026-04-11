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
