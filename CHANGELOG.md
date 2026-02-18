# Changelog

All notable changes to Spot2 are documented here.
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.5.0] — 2026-02-18

### Upgrade guide

**Zero code changes required** for existing applications. Bump the version
constraint in your `composer.json` and run `composer update`:

```json
{
    "require": {
        "beyondautomation/spot": "^2.5",
        "doctrine/dbal": "^4.0"
    }
}
```

---

### Added

- `declare(strict_types=1)` in every library file.
- Return types on all public API methods across `Mapper`, `Query`,
  `Query\Resolver`, `Entity\Collection`, `Locator`, and operator classes.
- PHPDoc `@param` and `@return` types on all previously untyped methods.
- `Query::order()` now validates that sort directions are `ASC` or `DESC`,
  throwing `\InvalidArgumentException` on any other value.
- `Entity\Collection::add()` now has a `void` return type.
- `Entity\Collection::run()` now accepts `callable` instead of a mixed
  `string|array` callback.
- `Entity\Collection::map()` and `filter()` now declare `callable` parameter
  types and proper return types.

### Changed

- **[DBAL4]** `Connection::PARAM_STR_ARRAY` replaced with
  `\Doctrine\DBAL\ArrayParameterType::STRING` in `Operator\In`, `Operator\Not`,
  and `Operator\Equals`. This is required for DBAL ^4.0 compatibility.
- **[DBAL4]** `getSchemaManager()` → `createSchemaManager()`.
- **[DBAL4]** `listTableDetails()` → `introspectTable()` + `tablesExist()`.
- **[DBAL4]** `getMigrateToSql()` → `compareSchemas()` + `getAlterSchemaSQL()`.
- **[DBAL4]** `exec()` → `executeStatement()`.
- **[DBAL4]** `$qb->execute()` → `$qb->executeQuery()` / `executeStatement()`.
- **[DBAL4]** `fetchColumn(0)` → `fetchOne()`.
- **[DBAL4]** `resetQueryPart('orderBy')` → `resetOrderBy()`.
- **[Security]** `Query::search()` now quotes field names using DBAL's
  `quoteIdentifier()` instead of raw backtick wrapping.
- **[Security]** `Query::order()` validates sort direction against an
  `['ASC', 'DESC']` whitelist before passing it to DBAL.
- **[PSR-12]** All files now use LF line endings, 4-space indentation, and
  a blank line after the opening `<?php` tag.
- **[PHP 8.5]** `strLen()` (wrong capitalisation) replaced with `strlen()`.
- **[PHP 8.5]** `str_contains()` replaces `strpos() !== false` throughout.
- `Mapper::collection()` no longer accepts `\PDOStatement` objects directly;
  callers should wrap raw results in `new \ArrayIterator($result->fetchAllAssociative())`.
- `Mapper::query()` wraps DBAL4 `Result` in `ArrayIterator` before hydration.
- Class constants updated from `const FOO` to `public const FOO`.
- `Query::ALL_FIELDS` changed to `public const ALL_FIELDS`.

### Deprecated

Nothing deprecated in this release.

### Removed

- `$stmt->setFetchMode(\PDO::FETCH_ASSOC)` call in `Mapper::collection()`
  (PDOStatement guard retained for backward compatibility).
- `$stmt->closeCursor()` calls (DBAL4 Result auto-releases).

### Fixed

- `Query\Resolver::dropTable()` no longer silently swallows all exceptions —
  only table-not-found scenarios return `false`.
- `Query\Resolver::addForeignKeys()` correctly uses `elseif` for `onDelete`
  cascading logic (was `else if`).

---

## [2.0.x] — prior releases

See [GitHub releases](https://github.com/beyondautomation/spot2/releases) for
previous release notes.
