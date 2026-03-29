# Contributing

## Setup

```bash
composer install
```

## Commands

```bash
composer test   # PHPUnit
composer stan   # PHPStan (level 8)
composer pint   # Pint (PSR-12 preset)
```

## Coding Standards

- All files must have `declare(strict_types=1)`
- **PHPStan level 8** — suppressing errors via `@phpstan-ignore` is not allowed; fix the root cause
- **Pint** — PSR-12 preset (`pint.json`)
- Do not abbreviate variable names (`$idx` → `$selectedIndex`, etc.)

## Testing

- **TDD required** — write tests before implementation (Red → Green → Refactor)
- **Unit Tests**: AAA format (`// Arrange`, `// Act`, `// Assert`)
- **Feature Tests**: BDD/Gherkin format (`// Given`, `// When`, `// Then`)
- **Combinatorial tests**: use `#[DataProvider]`
- All assertions must include a message describing what is being verified and what the expected result is
- Test method names must follow the `test_snake_case_describing_behavior` convention

---

## Branch Strategy

| Branch | Purpose |
|---|---|
| `main` | Stable. No direct push — PRs only |
| `feat/xxx` | New features |
| `fix/xxx` | Bug fixes |
| `docs/xxx` | Documentation-only changes |
| `refactor/xxx` | Refactoring without changing external behaviour |

---

## Issues

### Bug Reports

Please include:

- PHP version
- Minimal reproducible code
- Expected behaviour vs actual behaviour

### Feature Requests

- Describe the use case first ("I want to be able to…")
- Include an implementation proposal if you have one

---

## Pull Requests

1. Branch off `main` as `feat/xxx` or `fix/xxx`
2. Implement the change and add tests
3. Ensure all CI checks pass
4. Open a PR and include:
   - **What** was changed
   - **Why** it was changed
   - **How** it was tested

### PR Rules

- Feature additions without tests will not be accepted
- PRs with PHPStan level 8 errors will not be merged
- One PR = one purpose (keep PRs reviewable)
- Force push to `main` is prohibited
