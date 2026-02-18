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

| Category      | Types                                         |
|---------------|-----------------------------------------------|
| Integer       | `smallint`, `integer`, `bigint`               |
| Decimal       | `decimal`, `float`                            |
| String        | `string`, `text`, `guid`                      |
| Binary        | `binary`, `blob`                              |
| Boolean       | `boolean`                                     |
| Date / Time   | `date`, `datetime`, `datetimetz`, `time`      |
| Array / Object| `array`, `simple_array`, `object`             |

> **Note:** DBAL4 requires `\DateTime` objects for datetime fields. `\DateTimeImmutable` values are automatically coerced by Spot 2.5.

### Custom Field Types

Register custom DBAL types as normal — Spot uses DBAL's type system directly:

```php
use Doctrine\DBAL\Types\Type;
Type::addType('encrypted', \App\Core\Encryption\DBALEncrypted::class);
```

Custom DBAL type classes must implement the typed method signatures required by DBAL 4:

```php
public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed {}
public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed {}
public function getSqlDeclaration(array $fieldDeclaration, AbstractPlatform $platform): string {}
public function getName(): string {}
```

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
$posts = $mapper->all()->with(['comments', 'tags']);
```

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

Available events: `beforeSave`, `afterSave`, `beforeInsert`, `afterInsert`, `beforeUpdate`, `afterUpdate`, `beforeDelete`, `afterDelete`, `afterLoad`.

---

## Migrations

Spot can auto-create and alter tables based on your entity field definitions:

```php
$mapper->migrate();
```

---

## Development

```bash
# Run tests (SQLite — no setup required)
composer test

# Run static analysis (PHPStan level 8)
composer analyse

# Check code style
composer cs:check

# Auto-fix code style
composer cs:fix

# Preview Rector refactors
composer rector:dry

# Run everything (CI pipeline)
composer check
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

- `DateTimeType` strictly requires `\DateTime` — `\DateTimeImmutable` is coerced automatically by Spot 2.5, but you may want to use `\DateTime` explicitly.
- Custom DBAL type classes must add typed method signatures (see [Custom Field Types](#custom-field-types)).
- Removed DBAL APIs: `fetchColumn()` → `fetchOne()`, `fetchAll()` → `fetchFirstColumn()` / `fetchAllAssociative()`, `fetchArray()` → `fetchNumeric()`.

### 4. `BelongsTo` and `HasOne` now auto-resolve

Accessing a `belongsTo` or `hasOne` relation on an entity now returns the resolved entity directly instead of the relation proxy object. Code that relied on receiving the proxy object (e.g. calling `->execute()` explicitly) should be updated to use the entity directly.

### 5. `Locator::mapper()` throws `InvalidArgumentException` for unknown classes

Previously threw a PHP `Error`. Catch `\InvalidArgumentException` instead.

---

## License

BSD-3-Clause. Originally created by [Vance Lucas](http://www.vancelucas.com). Maintained by [Beyond Automation](https://github.com/beyondautomation).
