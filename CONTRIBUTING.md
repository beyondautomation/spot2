# Contributing to Spot2

## Prerequisites

- PHP 8.1 or higher
- Composer
- SQLite (for quick local tests — no server needed)
- MySQL or PostgreSQL (optional, for driver-specific test runs)

---

## Quick start

```bash
git clone https://github.com/beyondautomation/spot2
cd spot2
composer install
composer test          # SQLite — runs immediately, no setup
```

---

## Running the tests

### SQLite (recommended for development)

No database server needed:

```bash
composer test
```

> **Windows users:** `composer test` works as-is for SQLite. For MySQL/PostgreSQL, set `SPOT_DB_DSN` first: `set SPOT_DB_DSN=mysql://root@localhost/spot_test && composer test:mysql`

### MySQL

Create the database first, then run:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS spot_test;"
composer test:mysql

# Custom DSN:
SPOT_DB_DSN="mysql://user:password@localhost/mydb" composer test:mysql
```

### PostgreSQL

```bash
psql -U postgres -c "CREATE DATABASE spot_test;"
composer test:pgsql

# Custom DSN:
SPOT_DB_DSN="pgsql://user:password@localhost/mydb" composer test:pgsql
```

### Coverage report

Requires `pcov` or `xdebug` extension:

```bash
composer test:coverage
open coverage/index.html
```

---

## Code quality tools

### Check everything at once (CI-equivalent)

```bash
composer check
```

This runs `cs:check` → `analyse` → `test` in sequence.

### Code style

```bash
composer cs:check      # show violations without changing files
composer cs:fix        # auto-fix all violations
```

Style rules are defined in `.php-cs-fixer.php`. The project follows **PSR-12**
with a few extras (ordered imports, trailing commas, `declare(strict_types=1)`).

### Static analysis (PHPStan)

```bash
composer analyse
```

Configuration is in `phpstan.neon`. Current target: **level 5**.

### Automated refactoring (Rector)

Rector is included to help catch upgrade opportunities:

```bash
composer rector:dry    # preview changes — safe, nothing is modified
composer rector:fix    # apply the changes
```

Configuration is in `rector.php`.

---

## CI pipeline

GitHub Actions runs the following matrix on every pull request:

| Step         | Command              |
|--------------|----------------------|
| Code style   | `composer cs:check`  |
| Static analysis | `composer analyse` |
| Tests (SQLite) | `composer test`    |

To replicate locally:

```bash
composer check
```

---

## Coding conventions

- All files must begin with `<?php` followed by `declare(strict_types=1);`
- Follow PSR-12 — enforced by PHP CS Fixer
- Add `@param` and `@return` DocBlocks to all public methods
- New operators go in `lib/Query/Operator/` and must be registered in `Query::$_whereOperators`
- New tests go in `tests/` and must use `namespace SpotTest;`

## Submitting a pull request

1. Fork the repository and create a branch from `main`
2. Run `composer check` to verify everything passes
3. Add or update tests to cover your changes
4. Update `CHANGELOG.md` under `[Unreleased]`
5. Open a pull request with a clear description of what and why
