# Spot DataMapper ORM v2.5

[![PHPStan Level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](https://phpstan.org)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://php.net)
[![Doctrine DBAL 4](https://img.shields.io/badge/DBAL-4.x-orange)](https://www.doctrine-project.org/projects/dbal.html)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue)](LICENSE.txt)

Spot v2.5 is a lightweight DataMapper ORM built on [Doctrine DBAL 4](https://www.doctrine-project.org/projects/dbal.html), targeting PHP 8.3+.

The aim of Spot is to be a clear, efficient, and simple alternative to heavier ORMs — without annotations or proxy classes.

## Requirements

- PHP **8.3** or higher
- Doctrine DBAL **4.x**
- sabre/event **6.x**

> **Upgrading from Spot 2.x?** See the [Migration Guide](#migration-from-spot-2x) below.

---

## Installation

```bash
composer require beyondautomation/spot2
```

---

## Connecting to a Database

The `Spot\Locator` is your main entry point. It manages configuration and mapper instances. Pass it a `Spot\Config` with your connection details:

```php
$cfg = new \Spot\Config();

// MySQL / MariaDB
$cfg->addConnection('mysql', 'mysql://user:password@localhost/database_name');

// SQLite
$cfg->addConnection('sqlite', 'sqlite://path/to/database.sqlite');

$spot = new \Spot\Locator($cfg);
```

You can also use a DBAL-compatible array instead of a DSN:

```php
$cfg->addConnection('mysql', [
    'dbname'   => 'mydb',
    'user'     => 'user',
    'password' => 'secret',
    'host'     => 'localhost',
    'driver'   => 'pdo_mysql',
]);
```

---

## Getting a Mapper

```php
$postMapper = $spot->mapper('Entity\Post');
```

Mappers are entity-specific. You don't need to create a custom mapper for every entity — Spot will use the generic `Spot\Mapper` if none is defined.

---

## Creating Entities

Entity classes extend `\Spot\Entity` and define their fields and relations as static methods. In Spot 2.5, **static property types must be declared** to match the base class:

```php
namespace Entity;

use Spot\EntityInterface as Entity;
use Spot\MapperInterface as Mapper;

class Post extends \Spot\Entity
{
    protected static ?string $table = 'posts';

    public static function fields(): array
    {
        return [
            'id'           => ['type' => 'integer', 'autoincrement' => true, 'primary' => true],
            'title'        => ['type' => 'string', 'required' => true],
            'body'         => ['type' => 'text', 'required' => true],
            'status'       => ['type' => 'integer', 'default' => 0, 'index' => true],
            'author_id'    => ['type' => 'integer', 'required' => true],
            'date_created' => ['type' => 'datetime', 'value' => new \DateTime()],
        ];
    }

    public static function relations(Mapper $mapper, Entity $entity): array
    {
        return [
            'tags'     => $mapper->hasManyThrough($entity, 'Entity\Tag', 'Entity\PostTag', 'tag_id', 'post_id'),
            'comments' => $mapper->hasMany($entity, 'Entity\Post\Comment', 'post_id')->order(['date_created' => 'ASC']),
            'author'   => $mapper->belongsTo($entity, 'Entity\Author', 'author_id'),
        ];
    }
}
```

### Required static property declarations (2.5+)

All entity subclasses must now declare typed static properties to match the base `\Spot\Entity`:

```php
protected static ?string $table = 'my_table';          // table name
protected static ?string $connection = null;            // named connection (or null for default)
protected static string|false $mapper = false;          // custom mapper class (or false for default)
protected static array $tableOptions = [];              // extra table options for migrations
```

---

## Using Custom Mappers

Specify a custom mapper class on your entity:

```php
class Post extends \Spot\Entity
{
    protected static ?string $table = 'posts';
    protected static string|false $mapper = 'Entity\Mapper\Post';
    // ...
}
```

Then create your mapper:

```php
namespace Entity\Mapper;

use Spot\Mapper;

class Post extends Mapper
{
    public function mostRecentForSidebar(): \Spot\Query
    {
        return $this->where(['status' => 'active'])
            ->order(['date_created' => 'DESC'])
            ->limit(10);
    }
}
```

---

## Field Types

Spot uses all [Doctrine DBAL types](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.0/reference/types.html):

| Category       | Types                                         |
|----------------|-----------------------------------------------|
| Integer        | `smallint`, `integer`, `bigint`               |
| Decimal        | `decimal`, `float`                            |
| String         | `string`, `text`, `guid`                      |
| Binary         | `binary`, `blob`                              |
| Boolean        | `boolean`                                     |
| Date / Time    | `date`, `datetime`, `datetimetz`, `time`      |
| Array / Object | `array`, `simple_array`, `object`             |

> **Note:** `array`, `simple_array`, and `object` were removed as native DBAL4
> types. Spot maps them to `text` and handles serialization/unserialization
> transparently — no changes required in your entity definitions.

> **Note:** DBAL4 requires `\DateTime` for datetime fields.
> `\DateTimeImmutable` values are automatically coerced in both field writes
> and `->where()` calls.

### Custom Field Types

Register custom DBAL types before creating your `Spot\Locator`. Spot will
automatically handle them in schema migration, type conversion, and reads:

```php
use Doctrine\DBAL\Types\Type;

Type::addType('encrypted', \App\Core\Encryption\DBALEncrypted::class);
Type::addType('uuid',      \Ramsey\Uuid\Doctrine\UuidType::class);
Type::addType('timestamp', \App\Core\Encryption\TimestampType::class);
```

Custom DBAL type classes must implement the typed signatures required by DBAL 4:

```php
public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed {}
public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed {}
public function getSQLDeclaration(array $column, AbstractPlatform $platform): string {}
public function getName(): string {}
```

> **Note on `timestamp` fields:** The `value` option (e.g. `'value' => time()`)
> is applied at the PHP level when a new entity is instantiated. It is **not**
> emitted as a SQL `DEFAULT` clause — Spot strips runtime PHP expressions from
> schema generation to prevent invalid DDL.

---

## Querying

```php
// Fetch all posts
$posts = $mapper->all();

// With conditions
$posts = $mapper->where(['status' => 1])->order(['date_created' => 'DESC'])->limit(20);

// Single record
$post = $mapper->first(['title' => 'Hello World']);

// By primary key
$post = $mapper->get(1);

// DateTimeImmutable works in WHERE clauses
$recent = $mapper->where(['date_created :gt' => new \DateTimeImmutable('-7 days')]);
```

---

## Saving & Deleting

```php
// Insert
$post = new Entity\Post(['title' => 'Hello', 'body' => '...', 'author_id' => 1]);
$mapper->save($post);

// Update
$post->title = 'Updated Title';
$mapper->save($post);

// Delete
$mapper->delete($post);
$mapper->delete(['status' => 0]); // delete by condition
```

---

## Relations

### HasOne

The related entity holds the foreign key pointing to the current entity.

```php
public static function relations(Mapper $mapper, Entity $entity): array
{
    return [
        'profile' => $mapper->hasOne($entity, 'Entity\User\Profile', 'user_id'),
    ];
}
```

### BelongsTo

The current entity holds the foreign key pointing to the related entity.
If the foreign key is `NULL`, accessing the relation returns `null` — it never
returns data from another entity in the collection.

```php
public static function relations(Mapper $mapper, Entity $entity): array
{
    return [
        'author' => $mapper->belongsTo($entity, 'Entity\Author', 'author_id'),
    ];
}
```

### HasMany

One-to-many: the related entities hold the foreign key.

```php
public static function relations(Mapper $mapper, Entity $entity): array
{
    return [
        'comments' => $mapper->hasMany($entity, 'Entity\Comment', 'post_id')
                              ->order(['date_created' => 'ASC']),
    ];
}
```

### HasManyThrough

Many-to-many via a join entity.

```php
public static function relations(Mapper $mapper, Entity $entity): array
{
    return [
        'tags' => $mapper->hasManyThrough($entity, 'Entity\Tag', 'Entity\PostTag', 'tag_id', 'post_id'),
    ];
}
```

### Eager Loading

Solve the N+1 problem with `with()`:

```php
$posts = $mapper->all()->with('comments');
$posts = $mapper->all()->with(['comments', 'tags', 'author']);
```

Relations that have no matching rows return `null` (for `BelongsTo`/`HasOne`)
or an empty `Collection` (for `HasMany`/`HasManyThrough`) — never a stale
result from another entity.

---

## Events

```php
public static function events(\Spot\EventEmitter $emitter): void
{
    $emitter->on('beforeInsert', function (EntityInterface $entity, MapperInterface $mapper) {
        $entity->date_created = new \DateTime();
    });

    $emitter->on('beforeUpdate', function (EntityInterface $entity, MapperInterface $mapper) {
        $entity->date_modified = new \DateTime();
    });
}
```

Available events: `beforeSave`, `afterSave`, `beforeInsert`, `afterInsert`,
`beforeUpdate`, `afterUpdate`, `beforeDelete`, `afterDelete`, `afterLoad`,
`beforeWith`, `afterWith`, `loadWith`.

---

## Migrations

Spot can auto-create and alter tables based on your entity field definitions.
`migrate()` is **strictly additive** — it will never drop columns, tables, or
indexes, even if the DBAL schema comparator generates destructive DDL internally.

```php
$mapper->migrate();
```

### Migration behaviour

- Tables that do not exist are **created**.
- Missing columns are **added**.
- Existing columns are **modified** only when the type or constraint changes.
- `DROP TABLE`, `DROP COLUMN`, `DROP INDEX`, and `DROP FOREIGN KEY` statements
  are always suppressed.
- `string` columns without an explicit length, or with a length over 16 383
  characters, are automatically promoted to `text` (MySQL utf8mb4 limit).
- `datetime`, `timestamp`, `date`, and `time` fields that use `'value' => ...`
  do not emit a SQL `DEFAULT` clause.
- The introspected DB schema is cached per-connection per-request, so
  `migrate()` on 100+ entities only calls `INFORMATION_SCHEMA` once.

### Pre-production audit

Before deploying to production, run the audit script against a copy of your
production database to see exactly what SQL would be executed:

```bash
php audit_schema.php \
    --dsn="mysql://user:pass@host/prod_copy" \
    --bootstrap="/path/to/app/bootstrap.php"
```

Exit codes: `0` = safe to deploy, `1` = schema changes pending (review first),
`2` = errors (do not deploy). Use `--help` for all options.

---

## Development

```bash
composer install

# Run tests (SQLite — no setup required)
composer test

# Run tests against MySQL or PostgreSQL
composer test:mysql
composer test:pgsql

# Code style (PSR-12)
composer cs          # check only
composer cs:fix      # auto-fix

# Static analysis
composer analyse     # PHPStan level 8
composer psalm       # Psalm level 3

# Run full pipeline (cs + analyse + psalm + test)
composer check

# Run full pipeline + mutation testing (requires Xdebug or PCOV)
composer check:full

# PHP syntax check
composer lint

# Preview / apply Rector refactors
composer refactor:dry
composer refactor

# Regenerate Psalm baseline after large refactor
composer psalm:baseline

# Pre-production schema audit
composer audit -- --dsn="mysql://..." --bootstrap="..."
```

---

## Migration from Spot 2.x

Spot 2.5 introduces several **breaking changes** required by PHP 8.3+ and Doctrine DBAL 4.

### 1. Static property types required

All entity subclasses must now declare typed static properties:

```php
// Before (Spot 2.x)
protected static $table = 'posts';
protected static $mapper = 'App\Mapper\PostMapper';

// After (Spot 2.5)
protected static ?string $table = 'posts';
protected static string|false $mapper = 'App\Mapper\PostMapper';
```

### 2. `fields()`, `relations()`, and `events()` need return types

```php
// Before
public static function fields() { ... }
public static function relations(Mapper $mapper, Entity $entity) { ... }
public static function events(EventEmitter $emitter) { ... }

// After
public static function fields(): array { ... }
public static function relations(Mapper $mapper, Entity $entity): array { ... }
public static function events(EventEmitter $emitter): void { ... }
```

### 3. DBAL 4 type changes

- `DateTimeType` strictly requires `\DateTime` — `\DateTimeImmutable` is
  coerced automatically in both field saves and `->where()` calls.
- Custom DBAL type classes must use the typed method signatures required by
  DBAL 4 (see [Custom Field Types](#custom-field-types)).
- Removed DBAL APIs: `fetchColumn()` → `fetchOne()`,
  `fetchAll()` → `fetchAllAssociative()`, `fetchArray()` → `fetchNumeric()`.
- `array`, `simple_array`, and `object` field types are mapped to `text`
  transparently. No entity changes required.

### 4. `BelongsTo` and `HasOne` now auto-resolve

Accessing a `belongsTo` or `hasOne` relation on an entity now returns the
resolved entity directly (or `null` if the FK is `NULL`) instead of the
relation proxy object. Code that relied on receiving the proxy and calling
`->execute()` explicitly should use the entity directly.

### 5. `Locator::mapper()` throws `InvalidArgumentException` for unknown classes

Previously threw a PHP `Error`. Catch `\InvalidArgumentException` instead.

---

## License

BSD-3-Clause. Originally created by [Vance Lucas](http://www.vancelucas.com). Maintained by [Beyond Automation](https://github.com/beyondautomation).
