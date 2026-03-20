# Changelog

All notable changes to Spot2 are documented here.
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.5.8] — 2026-03-20

### Fixed

- **[Relation]** `BelongsTo::buildQuery()` and `HasOne::buildQuery()` now
  return an always-false query (`WHERE 1 = 0`) when `identityValue()` is
  `null` instead of generating `WHERE id IS NULL`. The previous behaviour
  could match unrelated rows with a NULL primary key and caused an
  unnecessary DB round-trip on every lazy-load of a null-FK field.
- **[Relation]** `BelongsTo::__set()` and `HasOne::__set()` now guard
  against `execute()` returning `false` (no related entity). Previously
  calling `$relation->field = $value` when there was no related entity
  would throw a fatal `TypeError: null->field`.
- **[Mapper]** `Mapper::transaction()` now initialises `$result = null`
  before the `try` block. If `beginTransaction()` threw an exception,
  `$result` would be undefined and the `return $result` at the end of
  the method would produce a PHP undefined variable warning.
- **[Interface]** `EntityInterface::relation()` signature updated to include
  the `bool $setNull = false` parameter added to `Entity::relation()` in this
  release. Without this, code type-checking against the interface would get a
  PHP fatal error at runtime.
- **[Collection]** `Entity\Collection::valid()` now uses `key($this->results) !== null`
  instead of `current($this->results) !== false`. The `current()` approach is
  the wrong SPL iterator validity check — `key()` is what PHP's own
  `ArrayIterator` uses and correctly distinguishes an exhausted iterator from
  a collection that happens to contain a falsy value.
- **[PHP 8.5]** `Provider\Laravel::$config` untyped property declared as
  `protected array $config = []`. `Provider\Laravel::register()` missing
  `void` return type added.
- **[Testing]** `Resolver::resetStaticCaches()` and `Mapper::resetStaticCaches()`
  public static methods added. PHPUnit runs all test classes in the same
  process, so static properties (`$migratingEntities`, `$introspectedSchemas`,
  `$entityManager`, `$eventEmitter`) would persist across test classes without
  these reset points. `MigrateRegression::setUpBeforeClass()` calls both.
- **[Relation]** `Entity::relation()` now supports an explicit null SET via a
  new `$setNull = true` parameter. Previously passing `null` was silently
  treated as a GET, meaning `eagerLoadOnCollection()` could not mark a
  relation as "loaded but empty". This caused `BelongsTo` and `HasOne`
  relations with a NULL foreign key to return a stale cached result from a
  previously-iterated entity in the same collection — phantom data. ([#phantom-fk])
- **[Relation]** `RelationAbstract::eagerLoadOnCollection()` now calls
  `relation($name, null, true)` for unmatched entities, storing a
  `RELATION_NULL` sentinel. This distinguishes "not yet loaded" from
  "loaded and empty" and prevents lazy-load fallback from returning wrong data.
- **[Relation]** `HasMany::save()` was using the hardcoded `$related->id`
  property instead of `$related->primaryKey()`. This silently failed for
  entities whose primary key field is not named `id`.
- **[Relation]** `HasManyThrough::eagerLoadOnCollection()` was using loose `==`
  comparison for primary key matching. Changed to strict `(string) ===` to
  prevent false-positive matches (e.g. `0 == 'somestring'` in PHP).
- **[Mapper]** `Mapper::with()` could throw a `TypeError` (`get_class(null)`)
  after the RELATION_NULL sentinel fix, because `relation()` can now return
  `null` for eagerly-loaded empty relations. Added a null re-fetch guard.
- **[Mapper]** `Entity::data()` could throw on entities whose `$relationFields`
  entry was not yet initialised (e.g. entities with no relations, or freshly
  constructed before `initFields()` runs). Added `?? []` null guard.
- **[Mapper]** `convertToPHPValues()` was missing `encrypted` and `uuid` from
  its legacy type map, while `convertToDatabaseValues()` had them. This caused
  a `Type not found` exception when loading entities with these types if the
  custom type was not registered before hydration.
- **[Query]** `Query::parseWhereToSQLFragments()` did not coerce
  `\DateTimeImmutable` values before passing them to DBAL, unlike
  `Mapper::convertToDatabaseValues()`. Added the same coercion so
  `->where(['date' => new \DateTimeImmutable(...)])` works correctly.
- **[Migrate]** `Resolver::addForeignKeys()` still called `tablesExist()` for
  the foreign table existence check, bypassing the per-request introspection
  cache. It now uses the cached schema via `hasTable()` where available.
- **[Types]** `uuid` type now falls back to DBAL's native `guid` type in all
  type maps (`migrateCreateSchema`, `convertToDatabaseValues`,
  `convertToPHPValues`) if `uuid` is not registered via `Type::addType()`.
  Applications that register the type via Ramsey's DBAL bridge (the recommended
  approach) are unaffected.

### Added

- **[Testing]** 42 new test methods across three new test classes:
  - `MigrateRegression` — 14 tests covering all schema migration bug fixes
    (circular FK recursion, destructive DDL, string promotion, timestamp
    DEFAULT, legacy types, idempotency, new column detection, cache
    invalidation).
  - `RelationRegression` — 16 tests covering the phantom-data bug and all
    relation edge cases (null FK eager-load, all-null collection, mixed
    collection, lazy-load, sentinel get/set/unset, toArray safety, re-fetch,
    empty HasMany, transformer guard, cross-entity bleed).
  - `EntityIntegrity` — 12 tests covering DateTimeImmutable coercion,
    non-standard PK handling, insert round-trip, update diff, isModified/isNew
    lifecycle, error clearing, toArray sentinel safety, unique/required
    validation, upsert, and transaction rollback.
- **[Testing]** 9 new entity fixture classes: `Article`, `Category`,
  `CircularA`, `CircularB`, `LegacyTypes`, `MigrateTimestamp`,
  `MigrateStringNoLength`, `CustomPkParent`, `CustomPkChild`.
- **[Tooling]** `audit_schema.php` — a pre-production schema audit script.
  Connects to a copy of your production database, runs every mapper's
  `migrate()` in dry-run mode, and reports what SQL would execute (CREATE,
  ALTER, or destructive DDL that would be suppressed). Exit codes: `0` = safe
  to deploy, `1` = schema changes pending review, `2` = errors. Designed for
  CI and pre-deploy pipelines.
- **[Tooling]** Psalm (`vimeo/psalm` ^5||^6) and Infection mutation testing
  (`infection/infection`) added to `require-dev`.
- **[Tooling]** Full composer command suite:
  `cs`, `cs:fix`, `analyse`, `psalm`, `psalm:baseline`, `lint`,
  `refactor:dry`, `refactor`, `mutation`, `check`, `check:full`, `audit`.
- `psalm.xml` configuration at level 3.
- `infection.json` configuration (targets 60% MSI / 70% covered MSI).

---

## [2.5.7] — 2026-03-10

### Fixed

- **[Migrate]** `Resolver::migrate()` now caches the result of
  `introspectSchema()` per connection for the duration of the request.
  Previously `introspectSchema()` was called on every `migrate()` invocation,
  performing a full `INFORMATION_SCHEMA` scan each time. On applications with
  many entities (e.g. 182) this caused migration runs of 6+ minutes. The cache
  is invalidated immediately after any schema-changing SQL is executed, so
  entity changes are always detected correctly.
- **[Migrate]** `tablesExist()` replaced by `hasTable()` on the cached schema
  in the main `migrate()` path, eliminating one extra DB round-trip per entity.

---

## [2.5.6] — 2026-03-10

### Fixed

- **[Migrate]** `migrateCreateSchema()` now maps `encrypted` to `text` in the
  schema type map (alongside `array`, `simple_array`, `object`). Previously
  DBAL's schema comparator could not match the introspected `TEXT` column
  against the `encrypted` custom type, generating a spurious `MODIFY COLUMN`
  statement that would attempt to narrow the column and truncate existing
  encrypted data.

---

## [2.5.5] — 2026-03-10

### Fixed

- **[Migrate]** `migrateCreateSchema()` no longer emits a SQL `DEFAULT` clause
  for `timestamp`, `datetime`, `datetimetz`, `date`, or `time` fields that use
  `'value' => time()` or `'value' => new \DateTime()`. DBAL4 was forwarding
  the PHP runtime integer (Unix timestamp) as a column default, producing
  `SQLSTATE[42000]: 1067 Invalid default value` in MySQL.

---

## [2.5.4] — 2026-03-10

### Fixed

- **[Migrate]** `Resolver::migrate()` is now protected against infinite
  recursion caused by circular foreign key chains (e.g. A→B→C→A). A static
  `$migratingEntities` registry tracks entities currently being migrated;
  re-entrant calls return `false` immediately and the original call completes
  normally.
- **[Migrate]** `Resolver::migrate()` now filters all destructive DDL from the
  SQL generated by DBAL4's schema comparator before executing it. `DROP TABLE`,
  `DROP INDEX`, and `ALTER TABLE ... DROP COLUMN / DROP FOREIGN KEY` clauses
  are stripped. `migrate()` is strictly additive — it never removes data.
- **[Migrate]** `migrateCreateSchema()` promotes `string` columns with no
  explicit length, or with a length exceeding 16 383 characters (the MySQL
  utf8mb4 VARCHAR limit), to `text`. DBAL4 changed the default `string` length
  from 255 to 65 535, which exceeds the MySQL utf8mb4 maximum and caused
  `SQLSTATE[42000]: Column length too big` on `ALTER TABLE`.

---

## [2.5.3] — 2026-02-19

### Fixed

- **[PostgreSQL]** `Mapper::insert()` no longer constructs the sequence name
  by guessing (`table_column_seq`). It now calls
  `pg_get_serial_sequence(table, column)` to retrieve the actual sequence name
  from PostgreSQL, which correctly handles quoted mixed-case column names.
  Falls back to `lastInsertId()` if the sequence cannot be determined.

---

## [2.5.2] — 2026-02-19

### Fixed

- **[Bernard/DBAL4]** Patched the Bernard DBAL driver to remove calls to
  `Connection::PARAM_STR_ARRAY` (removed in DBAL4) and replace
  `$stmt->execute()` with `$stmt->executeQuery()` / `executeStatement()`.
- **[DBAL4]** `TimestampType::getSQLDeclaration()` removed the call to
  `$platform->getName()` (removed in DBAL4) and replaced it with
  `instanceof` checks against `AbstractMySQLPlatform` and
  `AbstractSQLitePlatform`.

---

## [2.5.1] — 2026-02-19

### Fixed

- **[PHPUnit 11.5]** All docblock annotations (`@depends`, `@dataProvider`,
  `@group`) replaced with PHP attributes (`#[Depends]`, `#[DataProvider]`,
  `#[Group]`).
- **[PHPUnit 11.5]** Added `#[CoversNothing]` to all 23 test case classes.
- **[PHPUnit 11.5]** PHPUnit schema URL updated from `11.0` to `11.5`; added
  `requireCoverageMetadata="false"` and `beStrictAboutCoverageMetadata="false"`
  to all three config files.
- **[PHP 8.5]** `Encrypted::$key` untyped static property declared as
  `public static string $key = ''`.
- **[PHP 8.5]** `assertEquals(null, ...)` replaced with `assertNull()`.
- **[PHP 8.5]** Untyped static `$entities` properties in 17 test files
  declared as `private static array $entities`.
- **[Valitron]** `Validation::testHasOneRelationValidation` decorated with
  `#[IgnoreDeprecations]` to suppress a `strtotime(null)` deprecation in the
  third-party `valitron` library.

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
  and `Operator\Equals`.
- **[DBAL4]** `getSchemaManager()` → `createSchemaManager()`.
- **[DBAL4]** `listTableDetails()` → `introspectTable()` + `tablesExist()`.
- **[DBAL4]** `getMigrateToSql()` → `compareSchemas()` + `getAlterSchemaSQL()`.
- **[DBAL4]** `exec()` → `executeStatement()`.
- **[DBAL4]** `$qb->execute()` → `$qb->executeQuery()` / `executeStatement()`.
- **[DBAL4]** `fetchColumn(0)` → `fetchOne()`.
- **[DBAL4]** `resetQueryPart('orderBy')` → `resetOrderBy()`.
- **[Security]** `Query::search()` now quotes field names via
  `quoteIdentifier()` instead of raw backtick wrapping.
- **[Security]** `Query::order()` validates sort direction against an
  `['ASC', 'DESC']` whitelist.
- **[PSR-12]** All files now use LF line endings, 4-space indentation, and a
  blank line after the opening `<?php` tag.
- **[PHP 8.5]** `strLen()` replaced with `strlen()`.
- **[PHP 8.5]** `str_contains()` replaces `strpos() !== false` throughout.
- `Mapper::collection()` no longer accepts `\PDOStatement` objects directly.
- `Mapper::query()` wraps DBAL4 `Result` in `ArrayIterator` before hydration.
- Class constants updated to `public const`.
- `Query::ALL_FIELDS` changed to `public const ALL_FIELDS`.

### Removed

- `$stmt->setFetchMode(\PDO::FETCH_ASSOC)` call in `Mapper::collection()`.
- `$stmt->closeCursor()` calls (DBAL4 `Result` auto-releases).

### Fixed

- `Query\Resolver::dropTable()` no longer silently swallows all exceptions.
- `Query\Resolver::addForeignKeys()` correctly uses `elseif` for `onDelete`
  cascading logic.

---

## [2.0.x] — prior releases

See [GitHub releases](https://github.com/beyondautomation/spot2/releases) for
previous release notes.
