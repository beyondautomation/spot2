<?php

declare(strict_types=1);

namespace Spot;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;

/**
 * Base DataMapper — the primary interface for querying and persisting entities.
 *
 * Each Mapper instance is bound to a single entity class. It handles SELECT,
 * INSERT, UPDATE, DELETE, schema migration, validation, transactions, and
 * eager-loading of relations.
 *
 * @package Spot
 *
 * @author  Vance Lucas <vance@vancelucas.com>
 */
class Mapper implements MapperInterface
{
    /**
     * Per-entity Entity\Manager instances (keyed by entity class name).
     *
     * @var array<string, Entity\Manager>
     */
    protected static array $entityManager = [];

    /**
     * Per-entity EventEmitter instances (keyed by entity class name).
     *
     * @var array<string, EventEmitter>
     */
    protected static array $eventEmitter = [];

    protected Locator $locator;

    /** @var string Fully-qualified entity class name. */
    protected string $entityName;

    /** @var array<string> Relations to eager-load on the next query. */
    protected array $withRelations = [];

    /** @var class-string<Entity\Collection> */
    protected string $_collectionClass = Entity\Collection::class;

    /** @var class-string<Query> */
    protected string $_queryClass = Query::class;

    /** @var array<string, callable> Loaded event hooks. */
    protected array $_hooks = [];

    /**
     * @param Locator $locator    The service locator / config container.
     * @param string  $entityName Fully-qualified entity class name.
     */
    public function __construct(Locator $locator, string $entityName)
    {
        $this->locator    = $locator;
        $this->entityName = $entityName;

        $this->loadEvents();
    }

    // =========================================================================
    // Infrastructure
    // =========================================================================

    /**
     * Get the Spot Config from the locator.
     */
    #[\Override]
    public function config(): Config
    {
        return $this->locator->config();
    }

    /**
     * Get a mapper for a different entity class.
     */
    #[\Override]
    public function getMapper(string $entityName): Mapper
    {
        return $this->locator->mapper($entityName);
    }

    /**
     * Return the entity class name this mapper is bound to.
     */
    #[\Override]
    public function entity(): string
    {
        return $this->entityName;
    }

    /**
     * Return the query class name.
     *
     * @return class-string<Query>
     */
    #[\Override]
    public function queryClass(): string
    {
        return $this->_queryClass;
    }

    /**
     * Return the collection class name.
     *
     * @return class-string<Entity\Collection>
     */
    #[\Override]
    public function collectionClass(): string
    {
        return $this->_collectionClass;
    }

    /**
     * Get (or create) the Entity\Manager for the bound entity.
     */
    #[\Override]
    public function entityManager(): Entity\Manager
    {
        if (!isset(static::$entityManager[$this->entityName])) {
            static::$entityManager[$this->entityName] = new Entity\Manager($this->entityName);
        }

        return static::$entityManager[$this->entityName];
    }

    /**
     * Get (or create) the EventEmitter for the bound entity.
     */
    #[\Override]
    public function eventEmitter(): EventEmitter
    {
        if (!isset(static::$eventEmitter[$this->entityName])) {
            static::$eventEmitter[$this->entityName] = new EventEmitter();
        }

        return static::$eventEmitter[$this->entityName];
    }

    /**
     * Load all entity events into the EventEmitter.
     */
    #[\Override]
    public function loadEvents(): void
    {
        $entityName = $this->entityName;
        $entityName::events($this->eventEmitter());
    }

    /**
     * Maximum relation nesting depth. Relations at this depth and beyond are
     * registered as lazy proxies but their own relations are not loaded,
     * preventing infinite recursion when related entities have back-references.
     *
     * Depth 1 = direct relations of the loaded entity are registered (default).
     * Set to 0 to disable all automatic relation loading.
     */
    public static int $maxRelationDepth = 1;

    /**
     * Tracks the current nesting depth across all mapper instances. Static so
     * that recursion through different mappers (e.g. Post → Author → Post) is
     * correctly detected even though each entity type has its own mapper.
     */
    private static int $relationDepth = 0;

    /**
     * True while loadRelations() is executing. Models can check this flag in
     * their relations() method to skip expensive ->with() sub-relation
     * configuration during auto-loading, only applying it during explicit queries.
     *
     * Usage in a model:
     *   if (!\Spot\Mapper::$loadingRelations) {
     *       $rel->with(['subrelation']);
     *   }
     */
    public static bool $loadingRelations = false;

    public function loadRelations(EntityInterface $entity): void
    {
        if (self::$relationDepth >= self::$maxRelationDepth) {
            return;
        }

        self::$relationDepth++;
        self::$loadingRelations = true;

        try {
            $entityName = $this->entityName;
            $relations  = $entityName::relations($this, $entity);

            foreach ($relations as $relationName => $relation) {
                $entity->relation($relationName, $relation);
            }
        } finally {
            self::$relationDepth--;
            // Only clear the flag when fully unwound
            if (self::$relationDepth === 0) {
                self::$loadingRelations = false;
            }
        }
    }

    /**
     * Create a HasMany relation.
     */
    #[\Override]
    public function hasMany(EntityInterface $entity, string $entityName, string $foreignKey, mixed $localValue = null): Relation\HasMany
    {
        if ($localValue === null) {
            $localValue = $this->primaryKey($entity);
        }

        return new Relation\HasMany($this, $entityName, $foreignKey, $this->primaryKeyField(), $localValue);
    }

    /**
     * Create a HasManyThrough (many-to-many) relation.
     */
    #[\Override]
    public function hasManyThrough(
        EntityInterface $entity,
        string $hasManyEntity,
        string $throughEntity,
        string $selectField,
        string $whereField,
    ): Relation\HasManyThrough {
        $localValue = $this->primaryKey($entity);

        return new Relation\HasManyThrough($this, $hasManyEntity, $throughEntity, $selectField, $whereField, $localValue);
    }

    /**
     * Create a HasOne relation.
     */
    #[\Override]
    public function hasOne(EntityInterface $entity, string $foreignEntity, string $foreignKey): Relation\HasOne
    {
        $localValue = $this->primaryKey($entity);

        return new Relation\HasOne($this, $foreignEntity, $foreignKey, $this->primaryKeyField(), $localValue);
    }

    /**
     * Create a BelongsTo relation.
     */
    #[\Override]
    public function belongsTo(EntityInterface $entity, string $foreignEntity, string $localKey): Relation\BelongsTo
    {
        $localValue = $entity->$localKey;

        return new Relation\BelongsTo($this, $foreignEntity, $this->getMapper($foreignEntity)->primaryKeyField(), $localKey, $localValue);
    }

    /**
     * Prepare an entity by loading its relations.
     *
     * Returns true when relations were loaded, null when there is nothing to do.
     */
    #[\Override]
    public function prepareEntity(EntityInterface $entity): bool|null
    {
        if (count($this->entityManager()->fields()) > 0) {
            $this->loadRelations($entity);

            return true;
        }

        return null;
    }

    /**
     * Prepare entity after loading from the database (read path).
     * Loads relations and emits the afterLoad event.
     */
    public function prepareEntityAfterLoad(EntityInterface $entity): bool|null
    {
        if (count($this->entityManager()->fields()) > 0) {
            $this->loadRelations($entity);

            if (false === $this->eventEmitter()->emit('afterLoad', [$entity, $this])) {
                return false;
            }

            return true;
        }

        return null;
    }

    /**
     * Create a new Query\Resolver for this mapper.
     */
    #[\Override]
    public function resolver(): Query\Resolver
    {
        return new Query\Resolver($this);
    }

    /**
     * Get the table name defined on the entity.
     */
    #[\Override]
    public function table(): string
    {
        return $this->entityManager()->table();
    }

    /**
     * Get all field definitions with full defaults merged in.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public function fields(): array
    {
        return $this->entityManager()->fields();
    }

    /**
     * Get field definitions exactly as declared on the entity class.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public function fieldsDefined(): array
    {
        return $this->entityManager()->fieldsDefined();
    }

    /**
     * Get the relation definitions from the entity manager.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function relations(): array
    {
        return $this->entityManager()->relations();
    }

    /**
     * Get the scope closures defined on the entity.
     *
     * @return array<string, callable>
     */
    #[\Override]
    public function scopes(): array
    {
        return $this->entityName::scopes();
    }

    /**
     * Get the primary key value for a given entity.
     *
     * @throws Exception When no primary key field is defined.
     */
    #[\Override]
    public function primaryKey(EntityInterface $entity): mixed
    {
        $pkField = $this->entityManager()->primaryKeyField();

        if (empty($pkField)) {
            throw new Exception(
                get_class($entity) . ' has no primary key field. '
                . 'Please mark one of its fields as autoincrement or primary.',
            );
        }

        return $entity->$pkField;
    }

    /**
     * Get the primary key field name for the bound entity.
     *
     * @throws Exception When no primary key is defined.
     */
    #[\Override]
    public function primaryKeyField(): string
    {
        $pkField = $this->entityManager()->primaryKeyField();

        if ($pkField === false) {
            throw new Exception(
                $this->entityName . ' has no primary key field defined.',
            );
        }

        return $pkField;
    }

    /**
     * Check whether a field name is defined on the entity.
     */
    #[\Override]
    public function fieldExists(string $field): bool
    {
        return array_key_exists($field, $this->fields());
    }

    /**
     * Return the full field definition array for a field, or false if not found.
     *
     * @return array<string, mixed>|false
     */
    #[\Override]
    public function fieldInfo(string $field): array|false
    {
        return $this->fieldExists($field) ? $this->fields()[$field] : false;
    }

    /**
     * Return the type string for a field, or false if the field does not exist.
     */
    #[\Override]
    public function fieldType(string $field): string|false
    {
        $fields = $this->fields();

        return $this->fieldExists($field) ? $fields[$field]['type'] : false;
    }

    /**
     * Get the DBAL Connection for the bound entity.
     *
     * Resolves via the entity's declared connection name, falling back to the
     * default connection if none is specified.
     *
     * @param string|null $connectionName Optional named connection or entity class name override.
     *
     * @throws Exception When the connection cannot be resolved.
     */
    #[\Override]
    public function connection(?string $connectionName = null): Connection
    {
        $connectionName = $connectionName ?: $this->entityManager()->connection();

        if (empty($connectionName)) {
            return $this->config()->defaultConnection();
        }

        if ($connection = $this->config()->connection($connectionName)) {
            return $connection;
        }

        // Fall back to default connection if named connection not found.
        return $this->config()->defaultConnection();
    }

    /**
     * Check whether the active database driver matches a given type string.
     *
     * @param string $type Driver type substring to match (e.g. 'mysql', 'sqlite', 'pgsql').
     */
    #[\Override]
    public function connectionIs(string $type): bool
    {
        return str_contains(strtolower(get_class($this->connection()->getDriver())), $type);
    }

    // =========================================================================
    // Query building
    // =========================================================================

    /**
     * Create a new Query builder instance.
     */
    #[\Override]
    public function queryBuilder(): Query
    {
        return new $this->_queryClass($this);
    }

    /**
     * Start a SELECT query (default: all fields).
     *
     * @param string $fields Column expression to select (default '*').
     */
    #[\Override]
    public function select(string $fields = '*'): Query
    {
        return $this->queryBuilder()->select($fields)->from($this->table());
    }

    /**
     * Shorthand for select() — returns all records.
     */
    #[\Override]
    public function all(): Query
    {
        return $this->select();
    }

    /**
     * Start a SELECT with WHERE conditions.
     *
     * @param array<string, mixed> $conditions Conditions keyed by "field [operator]".
     */
    #[\Override]
    public function where(array $conditions = []): Query
    {
        return $this->select()->where($conditions);
    }

    /**
     * Return the first entity matching the given conditions, or false.
     *
     * @param array<string, mixed> $conditions
     */
    #[\Override]
    public function first(array $conditions = []): EntityInterface|false
    {
        $query = empty($conditions)
            ? $this->select()->limit(1)
            : $this->where($conditions)->limit(1);

        $collection = $query->execute();

        return $collection ? $collection->first() : false;
    }

    // =========================================================================
    // Collection building
    // =========================================================================

    /**
     * Hydrate a cursor of row data into a Collection of entity objects.
     *
     * @param iterable<array<string, mixed>> $cursor Row data (ArrayIterator, PDOStatement, etc.).
     * @param array<string>                  $with   Relation names to eager-load.
     */
    #[\Override]
    public function collection(iterable $cursor, array $with = []): Entity\Collection
    {
        $entityName        = $this->entity();
        $results           = [];
        $resultsIdentities = [];

        foreach ($cursor as $data) {
            $data = $this->convertToPHPValues($entityName, $data);
            /** @var EntityInterface $entity */
            $entity = new $entityName($data);
            $entity->isNew(false);

            $this->prepareEntityAfterLoad($entity);

            $results[] = $entity;

            $pk = $this->primaryKey($entity);

            if (!empty($pk) && !in_array($pk, $resultsIdentities, true)) {
                $resultsIdentities[] = $pk;
            }
        }

        /** @var Entity\Collection $collection */
        $collection = new $this->_collectionClass($results, $resultsIdentities, $entityName);

        // Don't eager-load nested with() relations when already inside a relation
        // load — prevents infinite recursion when relations define ->with([...])
        // on sub-relations (e.g. ClientUser -> roles ->with(['permissions'])).
        if (empty($with) || count($collection) === 0 || self::$relationDepth > 0) {
            return $collection;
        }

        return $this->with($collection, $entityName, $with);
    }

    // =========================================================================
    // CRUD
    // =========================================================================

    /**
     * Get an entity by primary key, by array of conditions, or create a new empty one.
     *
     * @param mixed $identifier Primary key scalar, array of conditions, or false for new entity.
     *
     * @return EntityInterface|false False when a PK lookup finds no record.
     */
    #[\Override]
    public function get(mixed $identifier = false): EntityInterface|false
    {
        $entityClass = $this->entity();
        $pkField     = $this->primaryKeyField();

        if ($identifier === false) {
            /** @var EntityInterface $entity */
            $entity = new $entityClass();
            $entity->data([$pkField => null]);
        } elseif (is_array($identifier)) {
            /** @var EntityInterface $entity */
            $entity = new $entityClass($identifier);
            $entity->data([$pkField => null]);
        } else {
            $entity = $this->first([$pkField => $identifier]);

            if (!$entity) {
                return false;
            }
        }

        if (!$this->primaryKey($entity)) {
            $entityDefaultValues = $this->entityManager()->fieldDefaultValues();

            if (count($entityDefaultValues) > 0) {
                $entity->data($entityDefaultValues);
            }
        }

        return $entity;
    }

    /**
     * Instantiate a new entity with the given data (does not persist).
     *
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function build(array $data): EntityInterface
    {
        $className = $this->entity();
        /** @var EntityInterface $entity */
        $entity = new $className($data);

        return $entity;
    }

    /**
     * Build and immediately persist a new entity.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options Options forwarded to insert().
     *
     * @throws Exception When the insert fails.
     */
    #[\Override]
    public function create(array $data, array $options = []): EntityInterface
    {
        $entity = $this->build($data);

        if ($this->insert($entity, $options)) {
            return $entity;
        }

        throw new Exception(
            'Unable to insert new ' . get_class($entity)
            . ' - Errors: ' . var_export($entity->errors(), true),
        );
    }

    /**
     * Execute a raw SQL query and return a Collection.
     *
     * @param string       $sql    SQL query string with optional ? placeholders.
     * @param array<mixed> $params Bound parameter values.
     */
    #[\Override]
    public function query(string $sql, array $params = []): Entity\Collection
    {
        $result = $this->connection()->executeQuery($sql, $params);

        return $this->collection(new \ArrayIterator($result->fetchAllAssociative()));
    }

    /**
     * Execute a raw SQL statement (UPDATE, DELETE, INSERT) and return affected row count.
     *
     * @param string       $sql    SQL statement string.
     * @param array<mixed> $params Bound parameter values.
     *
     * @return int Number of affected rows.
     */
    #[\Override]
    public function exec(string $sql, array $params = []): int
    {
        return (int) $this->connection()->executeStatement($sql, $params);
    }

    /**
     * Save an entity — inserts if new, updates if existing.
     *
     * @param array<string, mixed> $options
     *
     * @throws \InvalidArgumentException When the entity class doesn't match.
     */
    #[\Override]
    public function save(EntityInterface $entity, array $options = []): mixed
    {
        $entityName = $this->entity();

        if (!($entity instanceof $entityName)) {
            throw new \InvalidArgumentException(
                'Provided entity must be instance of ' . $entityName
                . ', instance of ' . get_class($entity) . ' given.',
            );
        }

        return $entity->isNew() ? $this->insert($entity, $options) : $this->update($entity, $options);
    }

    /**
     * Insert a new entity into the database.
     *
     * @param EntityInterface|array<string, mixed> $entity  Entity object or raw data array.
     * @param array<string, mixed>                 $options Options: validate, strict, relations.
     *
     * @throws Exception On invalid input or unknown fields (when strict mode is on).
     *
     * @return mixed Primary key value on success, false on failure.
     */
    #[\Override]
    public function insert(EntityInterface|array $entity, array $options = []): mixed
    {
        if (is_object($entity)) {
            $entityName = get_class($entity);
        } else {
            $entityName = $this->entity();
            $newEntity  = $this->get();

            if ($newEntity === false) {
                return false;
            }
            /** @var EntityInterface $entity */
            $entity = $newEntity->data($entity) ?? $newEntity;
        }

        if (
            false === $this->eventEmitter()->emit('beforeSave', [$entity, $this])
            || false === $this->eventEmitter()->emit('beforeInsert', [$entity, $this])
        ) {
            return false;
        }

        if (!isset($options['validate']) || $options['validate'] !== false) {
            if (!$this->validate($entity, $options)) {
                return false;
            }
        }

        $data = (array) $entity->data(null, true, false);

        if (count($data) === 0) {
            return false;
        }

        if (isset($options['relations']) && $options['relations'] === true) {
            $this->saveBelongsToRelations($entity, $options);
            $data = (array) $entity->data(null, true, false);
        }

        $pkField      = $this->primaryKeyField();
        $pkFieldInfo  = $this->fieldInfo($pkField);
        $entityFields = $this->fields();
        $extraData    = array_diff_key($data, $entityFields);
        $data         = array_intersect_key($data, $entityFields);

        if ((!isset($options['strict']) || $options['strict'] === true) && count($extraData) > 0) {
            throw new Exception(
                "Insert error: Unknown fields provided for $entityName: '"
                . implode("', '", array_keys($extraData)) . "'",
            );
        }

        $data = $this->convertToDatabaseValues($entityName, $data);

        if (array_key_exists($pkField, $data) && empty($data[$pkField])) {
            unset($data[$pkField]);
        }

        $result = $this->resolver()->create($this->table(), $data);

        if ($result) {
            $connection = $this->connection();

            if (array_key_exists($pkField, $data)) {
                $result = $data[$pkField];
            } elseif ($pkFieldInfo && $pkFieldInfo['autoincrement'] === true) {
                if ($this->connectionIs('pgsql')) {
                    // DBAL4: lastInsertId() no longer accepts a sequence name.
                    // Query the sequence directly for PostgreSQL autoincrement fields.
                    $fieldAliasMappings = $this->entityManager()->fieldAliasMappings();
                    $sequenceField      = $fieldAliasMappings[$pkField] ?? $pkField;
                    $sequenceName       = $this->table() . '_' . $sequenceField . '_seq';

                    if (isset($pkFieldInfo['sequence_name'])) {
                        $sequenceName = $pkFieldInfo['sequence_name'];
                    }
                    $result = $connection->fetchOne(
                        'SELECT currval(' . $connection->quote($sequenceName) . ')',
                    );
                } else {
                    $result = $connection->lastInsertId();
                }
            }
        }

        $entity->$pkField = ($pkFieldInfo && $pkFieldInfo['type'] === 'integer') ? (int) $result : $result;
        $entity->isNew(false);
        $entity->data((array) $entity->data(null, true, false), false);

        if (isset($options['relations']) && $options['relations'] === true) {
            $this->saveHasRelations($entity, $options);
        }

        if ($result) {
            $this->prepareEntityAfterLoad($entity);
        }

        if (
            false === $this->eventEmitter()->emit('afterSave', [$entity, $this, &$result])
            || false === $this->eventEmitter()->emit('afterInsert', [$entity, $this, &$result])
        ) {
            $result = false;
        }

        return $result;
    }

    /**
     * Update an existing entity in the database.
     *
     * @param array<string, mixed> $options Options: validate, strict, relations.
     *
     * @throws Exception On unknown fields when strict mode is on.
     */
    #[\Override]
    public function update(EntityInterface $entity, array $options = []): mixed
    {
        if (
            false === $this->eventEmitter()->emit('beforeSave', [$entity, $this])
            || false === $this->eventEmitter()->emit('beforeUpdate', [$entity, $this])
        ) {
            return false;
        }

        if (!isset($options['validate']) || $options['validate'] !== false) {
            if (!$this->validate($entity, $options)) {
                return false;
            }
        }

        if (isset($options['relations']) && $options['relations'] === true) {
            $this->saveBelongsToRelations($entity, $options);
        }

        $data         = (array) $entity->dataModified();
        $entityName   = $this->entity();
        $entityFields = $this->fields();
        $extraData    = array_diff_key($data, $entityFields);
        $data         = array_intersect_key($data, $entityFields);

        if ((!isset($options['strict']) || $options['strict'] === true) && count($extraData) > 0) {
            throw new Exception(
                "Update error: Unknown fields provided for $entityName: '"
                . implode("', '", array_keys($extraData)) . "'",
            );
        }

        $data = $this->convertToDatabaseValues($entityName, $data);

        if (count($data) > 0) {
            $result = $this->resolver()->update(
                $this->table(),
                $data,
                [$this->primaryKeyField() => $this->primaryKey($entity)],
            );
            $entity->data((array) $entity->data(null, true, false), false);

            if (isset($options['relations']) && $options['relations'] === true) {
                $this->saveHasRelations($entity, $options);
            }

            if (
                false === $this->eventEmitter()->emit('afterSave', [$entity, $this, &$result])
                || false === $this->eventEmitter()->emit('afterUpdate', [$entity, $this, &$result])
            ) {
                $result = false;
            }
        } else {
            $result = true;

            if (isset($options['relations']) && $options['relations'] === true) {
                $this->saveHasRelations($entity, $options);
            }
        }

        return $result;
    }

    /**
     * Insert or update — inserts first; if a unique-constraint validation error
     * occurs, finds the existing record by $where and updates it instead.
     *
     * @param array<string, mixed> $data  Data to set on the entity.
     * @param array<string, mixed> $where Conditions used to locate the existing record.
     */
    #[\Override]
    public function upsert(array $data, array $where): EntityInterface
    {
        $entityClass = $this->entity();
        /** @var EntityInterface $entity */
        $entity = new $entityClass($data);
        $result = $this->insert($entity);

        if ($result === false && $entity->hasErrors()) {
            $dataUpdate     = array_diff_key($data, $where);
            $existingEntity = $this->first($where);

            if (!$existingEntity) {
                return $entity;
            }
            $existingEntity->data($dataUpdate);
            $entity = $existingEntity;
            $this->update($entity);
        }

        return $entity;
    }

    /**
     * Save HasOne, HasMany, and HasManyThrough relations on the entity.
     *
     * The entity must already be persisted before calling this.
     *
     * @param array<string, mixed> $options
     *
     * @throws \InvalidArgumentException When the entity is new.
     */
    #[\Override]
    public function saveHasRelations(EntityInterface $entity, array $options = []): mixed
    {
        if ($entity->isNew()) {
            throw new \InvalidArgumentException(
                'The provided entity is new. It must be persisted before saving relations.',
            );
        }

        $relations  = $entity->relations($this, $entity);
        $lastResult = false;

        foreach ($relations as $relationName => $relation) {
            if (!($relation instanceof Relation\BelongsTo)) {
                $lastResult = $relation->save($entity, $relationName, $options);
            }
        }

        return $lastResult;
    }

    /**
     * Save BelongsTo relations on the entity.
     *
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function saveBelongsToRelations(EntityInterface $entity, array $options = []): mixed
    {
        $relations  = $entity->relations($this, $entity);
        $lastResult = false;

        foreach ($relations as $relationName => $relation) {
            if ($relation instanceof Relation\BelongsTo) {
                $lastResult = $relation->save($entity, $relationName, $options);
            }
        }

        return $lastResult;
    }

    /**
     * Delete records matching an entity object or condition array.
     *
     * @param EntityInterface|array<string, mixed> $conditions Entity or array of conditions.
     */
    #[\Override]
    public function delete(EntityInterface|array $conditions = []): mixed
    {
        $entityOrArray = $conditions;
        $beforeEvent   = 'beforeDelete';
        $afterEvent    = 'afterDelete';

        if (is_object($conditions)) {
            $conditions = [$this->primaryKeyField() => $this->primaryKey($conditions)];
        } else {
            if (isset($conditions['id'])) {
                $entityOrArray = $this->first([$this->primaryKeyField() => $conditions['id']]);
            }
            $beforeEvent = 'beforeDeleteConditions';
            $afterEvent  = 'afterDeleteConditions';
        }

        if (false === $this->eventEmitter()->emit($beforeEvent, [$entityOrArray, $this])) {
            return false;
        }

        $query  = $this->queryBuilder()->delete($this->table())->where($conditions);
        $result = $this->resolver()->exec($query);

        $this->eventEmitter()->emit($afterEvent, [$entityOrArray, $this, &$result]);

        return $result;
    }

    // =========================================================================
    // Type conversion
    // =========================================================================

    /**
     * Convert PHP values to their database representations.
     *
     * @param array<string, mixed> $data Field => PHP value pairs.
     *
     * @return array<string, mixed> Field => database value pairs.
     */
    #[\Override]
    public function convertToDatabaseValues(string $entityName, array $data): array
    {
        $dbData    = [];
        $fields    = $entityName::fields();
        $platform  = $this->connection()->getDatabasePlatform();

        $legacyTypeMap = ['array' => 'text', 'simple_array' => 'text', 'object' => 'text'];

        foreach ($data as $field => $value) {
            $originalFieldType = $fields[$field]['type'];

            // For legacy serialization types, serialize before storing.
            if (isset($legacyTypeMap[$originalFieldType]) && (is_array($value) || is_object($value))) {
                $value = serialize($value);
            }
            $fieldType   = $legacyTypeMap[$originalFieldType] ?? $originalFieldType;
            $typeHandler = Type::getType($fieldType);

            // DBAL4 DateTimeType strictly requires DateTime, not DateTimeImmutable.
            // Coerce transparently so existing code using DateTimeImmutable keeps working.
            if ($value instanceof \DateTimeImmutable
                && in_array($fieldType, ['datetime', 'date', 'time', 'datetimetz'], true)
            ) {
                $value = \DateTime::createFromImmutable($value);
            }

            $dbData[$field] = $typeHandler->convertToDatabaseValue($value, $platform);
        }

        return $dbData;
    }

    /**
     * Convert database values to their PHP representations.
     *
     * @param array<string, mixed> $data Column => raw database value pairs.
     *
     * @return array<string, mixed> Field => PHP value pairs.
     */
    #[\Override]
    public function convertToPHPValues(string $entityName, array $data): array
    {
        $phpData    = [];
        $fields     = $entityName::fields();
        $platform   = $this->connection()->getDatabasePlatform();
        $data       = $this->resolver()->dataWithOutFieldAliasMappings($data);
        $entityData = array_intersect_key($data, $fields);

        foreach ($data as $field => $value) {
            if (isset($entityData[$field])) {
                $legacyTypes  = ['array' => 'text', 'simple_array' => 'text', 'object' => 'text'];
                $originalType = $fields[$field]['type'];
                $fieldTypePHP = $legacyTypes[$originalType] ?? $originalType;
                $typeHandler  = Type::getType($fieldTypePHP);
                $phpValue     = $typeHandler->convertToPHPValue($value, $platform);

                // For legacy serialization types, unserialize the stored string value.
                if (isset($legacyTypes[$originalType]) && is_string($phpValue) && $phpValue !== '') {
                    $unserialized = @unserialize($phpValue);
                    $phpValue     = $unserialized !== false ? $unserialized : $phpValue;
                }
                $phpData[$field] = $phpValue;
            } else {
                // Extra columns from custom SQL (e.g. calculated values)
                $phpData[$field] = $value;
            }
        }

        return $phpData;
    }

    // =========================================================================
    // Transactions / schema operations
    // =========================================================================

    /**
     * Execute a Closure inside a database transaction.
     *
     * If the closure returns boolean false the transaction is rolled back.
     * Any exception also triggers a rollback before re-throwing.
     *
     * @param \Closure    $work           Closure receiving the Mapper as its argument.
     * @param string|null $connectionName Optional named connection override.
     *
     * @throws \Exception Re-throws any exception caught during the transaction.
     *
     * @return mixed The return value of the closure.
     */
    #[\Override]
    public function transaction(\Closure $work, ?string $connectionName = null): mixed
    {
        $connection = $this->connection($connectionName);

        try {
            $connection->beginTransaction();
            $result = $work($this);

            if ($result === false) {
                $connection->rollBack();
            } else {
                $connection->commit();
            }
        } catch (\Exception $e) {
            $connection->rollBack();

            throw $e;
        }

        return $result;
    }

    /**
     * Truncate the entity's table (delete all rows, reset auto-increment).
     *
     * @param bool $cascade PostgreSQL CASCADE option.
     *
     * @return int Number of affected rows.
     */
    #[\Override]
    public function truncateTable(bool $cascade = false): int
    {
        return $this->resolver()->truncate($this->table(), $cascade);
    }

    /**
     * Drop the entity's table from the database.
     *
     * @return bool True on success, false if the table didn't exist.
     */
    #[\Override]
    public function dropTable(): bool
    {
        return $this->resolver()->dropTable($this->table());
    }

    /**
     * Migrate the entity's table schema to match its current field definitions.
     */
    #[\Override]
    public function migrate(): bool|int
    {
        return $this->resolver()->migrate();
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * Validate the entity against its field rules.
     *
     * Fires beforeValidate and afterValidate events. Attaches errors directly
     * to the entity and returns false when any errors are found.
     *
     * @param array<string, mixed> $options Options: relations (bool).
     *
     * @return bool True when the entity is valid.
     */
    #[\Override]
    public function validate(EntityInterface $entity, array $options = []): bool
    {
        $v = new \Valitron\Validator((array) $entity->data());

        if (false === $this->eventEmitter()->emit('beforeValidate', [$entity, $this, $v])) {
            return false;
        }

        $uniqueWhere = [];

        foreach ($this->fields() as $field => $fieldAttrs) {
            if (
                (isset($fieldAttrs['required']) && $fieldAttrs['required'] === true)
                || ($fieldAttrs['primary'] === true && $fieldAttrs['autoincrement'] === false)
            ) {
                $v->rule('required', $field);
            }

            if ($entity->isNew() && isset($fieldAttrs['unique']) && !empty($fieldAttrs['unique']) && $entity->$field !== null) {
                if ($fieldAttrs['unique'] === true) {
                    $uniqueKeys = [$field];
                } elseif (is_string($fieldAttrs['unique'])) {
                    $uniqueKeys = [$fieldAttrs['unique']];
                } else {
                    $uniqueKeys = (array) $fieldAttrs['unique'];
                }

                foreach ($uniqueKeys as $fieldKeyName) {
                    $uniqueWhere[$fieldKeyName][$field] = $entity->$field;
                }
            }

            if ($entity->$field !== null || $fieldAttrs['required'] === true) {
                if (isset($fieldAttrs['options']) && is_array($fieldAttrs['options'])) {
                    $v->rule('in', $field, $fieldAttrs['options']);
                }

                if (isset($fieldAttrs['validation']) && is_array($fieldAttrs['validation'])) {
                    foreach ($fieldAttrs['validation'] as $rule => $ruleName) {
                        $params = [];

                        if (is_string($rule)) {
                            $params   = is_array($ruleName) ? $ruleName : [$ruleName];
                            $ruleName = $rule;
                        }
                        call_user_func_array([$v, 'rule'], array_merge([$ruleName, $field], $params));
                    }
                }
            }
        }

        foreach ($uniqueWhere as $field => $value) {
            $value = $this->convertToDatabaseValues($this->entity(), $value);

            if (!in_array(null, $value, true) && $this->first($value) !== false) {
                $entity->error(
                    $field,
                    ucwords(str_replace('_', ' ', (string) $field)) . " '" . implode('-', $value) . "' is already taken.",
                );
            }
        }

        if (!$v->validate()) {
            /** @var array<string, array<string>> $validationErrors */
            $validationErrors = $v->errors();
            $entity->errors($validationErrors, false);
        }

        if (isset($options['relations']) && $options['relations'] === true) {
            $this->validateRelations($entity);
        }

        if (false === $this->eventEmitter()->emit('afterValidate', [$entity, $this, $v])) {
            return false;
        }

        return !$entity->hasErrors();
    }

    /**
     * Eager-load named relations onto an existing collection.
     *
     * @param array<string> $with Relation names to load.
     *
     * @throws Exception On invalid relation names or object types.
     */
    /**
     * Eager-load relations onto a collection.
     *
     * Supports dot-notation for nested eager loading, e.g.:
     *   ->with(['profile', 'profile.country', 'profile.country.translations'])
     *
     * Each level is loaded in a single batch query regardless of collection size,
     * avoiding N+1 problems when Fractal (or other consumers) iterate sub-relations.
     *
     * @param array<string> $with Relation names, optionally dot-separated for nesting.
     */
    protected function with(Entity\Collection $collection, string $entityName, array $with = []): Entity\Collection
    {
        $eventEmitter = $this->eventEmitter();

        if (false === $eventEmitter->emit('beforeWith', [$this, $collection, $with])) {
            return $collection;
        }

        // Separate top-level relations from nested dot-notation ones.
        // e.g. ['profile', 'profile.country', 'profile.country.translations']
        // becomes: top = ['profile'], nested = ['profile' => ['country', 'country.translations']]
        $topLevel = [];
        $nested   = [];

        foreach ($with as $relationPath) {
            if (strpos($relationPath, '.') === false) {
                $topLevel[] = $relationPath;
            } else {
                [$parent, $rest] = explode('.', $relationPath, 2);
                $nested[$parent][] = $rest;
            }
        }

        // Ensure every parent of a nested path is also in topLevel
        foreach (array_keys($nested) as $parent) {
            if (!in_array($parent, $topLevel, true)) {
                $topLevel[] = $parent;
            }
        }

        foreach ($topLevel as $relationName) {
            $singleEntity = $collection->first();

            if (!($singleEntity instanceof Entity)) {
                throw new Exception(
                    "Relation object must be instance of 'Spot\\Entity', given '"
                    . (is_object($singleEntity) ? get_class($singleEntity) : 'false') . "'",
                );
            }

            $relationObject = $singleEntity->relation($relationName);

            if ($relationObject === false) {
                throw new Exception(
                    "Invalid relation name eager-loaded in 'with' clause: "
                    . "No relation on $entityName with name '$relationName'",
                );
            }

            if (!($relationObject instanceof Relation\RelationAbstract)) {
                throw new Exception(
                    "Relation object must be instance of 'Spot\\Relation\\RelationAbstract', given '"
                    . get_class($relationObject) . "'",
                );
            }

            if (false === $eventEmitter->emit('loadWith', [$this, $collection, $relationName])) {
                continue;
            }

            $collection = $relationObject->eagerLoadOnCollection($relationName, $collection);

            // If there are nested sub-relations for this parent, collect the related
            // entities from the now-hydrated collection and recursively eager-load them.
            if (!empty($nested[$relationName])) {
                // Build a sub-collection of all related entities for this relation
                $relatedEntities = [];
                foreach ($collection as $entity) {
                    $related = $entity->relation($relationName);
                    if ($related instanceof EntityInterface) {
                        $relatedEntities[] = $related;
                    } elseif ($related instanceof Entity\Collection) {
                        foreach ($related as $r) {
                            $relatedEntities[] = $r;
                        }
                    }
                }

                if (!empty($relatedEntities)) {
                    $relatedEntityName = get_class($relatedEntities[0]);
                    // Deduplicate by primary key to avoid loading same entity twice
                    $seen = [];
                    $unique = [];
                    $relatedMapper = $this->getMapper($relatedEntityName);
                    $pkField = $relatedMapper->primaryKeyField();
                    foreach ($relatedEntities as $r) {
                        $pk = $r->$pkField;
                        if ($pk !== null && isset($seen[$pk])) {
                            continue;
                        }
                        $seen[$pk] = true;
                        $unique[] = $r;
                    }

                    $subCollection = new $this->_collectionClass(
                        $unique,
                        array_keys($seen),
                        $relatedEntityName
                    );

                    // Recursively eager-load sub-relations on the related collection
                    $relatedMapper->with($subCollection, $relatedEntityName, $nested[$relationName]);
                }
            }
        }

        $eventEmitter->emit('afterWith', [$this, $collection, $with]);

        return $collection;
    }

    /**
     * Validate all loaded relations on the entity.
     */
    protected function validateRelations(EntityInterface $entity): EntityInterface
    {
        $relations = $entity->relations($this, $entity);

        foreach ($relations as $relationName => $relation) {
            if ($relation instanceof Relation\HasOne || $relation instanceof Relation\BelongsTo) {
                $relatedEntity = $entity->$relationName;

                if ($relatedEntity instanceof EntityInterface) {
                    $errorsRelated = $this->validateRelatedEntity($relatedEntity, $entity, $relation);

                    if (count($errorsRelated)) {
                        $entity->errors([$relationName => $errorsRelated], false);
                    }
                }
            } elseif ($relation instanceof Relation\HasMany || $relation instanceof Relation\HasManyThrough) {
                $relatedEntities      = $entity->$relationName;
                $isRelationCollection = $relatedEntities instanceof Entity\Collection
                    || $relatedEntities instanceof Relation\HasMany
                    || $relatedEntities instanceof Relation\HasManyThrough;

                if ($isRelationCollection && count($relatedEntities)) {
                    $errors = [];

                    foreach ($relatedEntities as $key => $related) {
                        if (!($related instanceof EntityInterface)) {
                            continue;
                        }

                        $errorsRelated = $this->validateRelatedEntity($related, $entity, $relation);

                        if (count($errorsRelated)) {
                            $errors[$key] = $errorsRelated;
                        }
                    }

                    if (count($errors)) {
                        /** @phpstan-ignore-next-line argument.type */
                        $entity->errors([$relationName => $errors], false);
                    }
                }
            }
        }

        return $entity;
    }

    /**
     * Validate a single related entity (only when it is new or modified).
     *
     * @return array<string, mixed> Validation errors (empty on success).
     */
    protected function validateRelatedEntity(
        EntityInterface $relatedEntity,
        EntityInterface $entity,
        Relation\RelationAbstract $relation,
    ): array {
        $tainted       = $relatedEntity->isNew() || $relatedEntity->isModified();
        $errorsRelated = [];

        if ($tainted && !$this->getMapper(get_class($relatedEntity))->validate($relatedEntity)) {
            $errorsRelated = $relatedEntity->errors();

            if (
                ($relation instanceof Relation\HasMany || $relation instanceof Relation\HasOne)
                && $relatedEntity->isNew()
            ) {
                unset($errorsRelated[$relation->foreignKey()]);
            }

            /** @phpstan-ignore-next-line argument.type */
            $relatedEntity->errors($errorsRelated);
        }

        if ($relation instanceof Relation\BelongsTo && $entity->isNew()) {
            $errors = $entity->errors();
            unset($errors[$relation->localKey()]);
            /** @phpstan-ignore-next-line argument.type */
            $entity->errors($errors);
        }

        return $errorsRelated;
    }
}
