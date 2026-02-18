<?php

declare(strict_types=1);

namespace Spot;

/**
 * Entity object
 *
 * @package Spot
 *
 * @author Vance Lucas <vance@vancelucas.com>
 */
abstract class Entity implements EntityInterface, \JsonSerializable
{
    /** @var string|null Table name for this entity. */
    protected static ?string $table = null;

    /** @var string|null Named connection to use (null = default). */
    protected static ?string $connection = null;

    /** @var array<string, mixed> Datasource options. */
    protected static array $tableOptions = [];

    /** @var string|false Custom mapper class name, or false to use the default Mapper. */
    protected static string|false $mapper = false;

    /**
     * Used internally so entity knows which fields are relations.
     *
     * @var array<string, array<string>>
     */
    protected static array $relationFields = [];

    protected string $_objectId;

    /** @var array<string, mixed> */
    protected array $_data_org = [];

    /** @var array<string, mixed> */
    protected array $_data = [];

    /** @var array<string, mixed> */
    protected array $_dataModified = [];

    /** @var bool Entity state — true when not yet persisted. */
    protected bool $_isNew = true;

    /** @var array<string, true> Re-entrancy guard for custom getter methods. */
    protected array $_inGetter = [];

    /** @var array<string, true> Re-entrancy guard for custom setter methods. */
    protected array $_inSetter = [];

    /**
     * Entity error messages (may be present after save attempt).
     *
     * @var array<string, array<string>>
     */
    protected array $_errors = [];

    /**
     * Constructor - allows setting of object properties with array on construct.
     *
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        // Generate unique object ID
        $this->_objectId = uniqid('entity_', true) . spl_object_hash($this);

        $this->initFields();

        // Set given data
        if ($data) {
            $this->_data_org = $data;
            $this->data($data, false);
        }
    }

    /**
     * Do some cleanup of stored relations so orphaned relations are not held in memory.
     */
    public function __destruct()
    {
        $entityName = get_class($this);

        if (isset(self::$relationFields[$entityName])) {
            foreach (self::$relationFields[$entityName] as $relation) {
                $this->relation($relation, false);
            }
        }
    }

    /**
     * Enable isset() for object properties.
     */
    #[\Override]
    public function __isset(mixed $key): bool
    {
        $entityName = get_class($this);

        return isset($this->_data[$key])
            || isset($this->_dataModified[$key])
            || in_array($key, self::$relationFields[$entityName] ?? [], true);
    }

    /**
     * Setter for field properties.
     *
     * @param string $field
     * @param mixed  $value
     */
    #[\Override]
    public function __set($field, $value): void
    {
        $this->set($field, $value);
    }

    /**
     * String representation of the class (JSON).
     */
    #[\Override]
    public function __toString(): string
    {
        return (string) json_encode($this->jsonSerialize());
    }

    /**
     * Table name getter/setter.
     */
    public static function table(?string $tableName = null): ?string
    {
        if ($tableName !== null) {
            static::$table = $tableName;
        }

        return static::$table;
    }

    /**
     * Connection name getter/setter.
     */
    public static function connection(?string $connectionName = null): ?string
    {
        if ($connectionName !== null) {
            static::$connection = $connectionName;
        }

        return static::$connection;
    }

    /**
     * Datasource options getter/setter.
     *
     * @param array<string, mixed>|null $tableOpts
     *
     * @return array<string, mixed>
     */
    public static function tableOptions(?array $tableOpts = null): array
    {
        if ($tableOpts !== null) {
            static::$tableOptions = $tableOpts;
        }

        return static::$tableOptions;
    }

    /**
     * Mapper name getter.
     */
    public static function mapper(): string|false
    {
        return static::$mapper;
    }

    /**
     * Return defined fields of the entity.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields(): array
    {
        return [];
    }

    /**
     * Add events to this entity.
     */
    public static function events(EventEmitter $eventEmitter): void
    {
    }

    /**
     * Return defined relations of the entity.
     *
     * @return array<string, mixed>
     */
    public static function relations(MapperInterface $mapper, EntityInterface $entity): array
    {
        return [];
    }

    /**
     * Return scopes defined by this entity.
     *
     * Scopes are called from the Spot\Query object as a sort of in-context
     * dynamic query method.
     *
     * @return array<string, callable>
     */
    public static function scopes(): array
    {
        return [];
    }

    /**
     * Gets and sets data on the current entity.
     *
     * @param array<string, mixed>|null $data
     *
     * @return $this|array<string, mixed>|null
     */
    public function data(?array $data = null, bool $modified = true, bool $loadRelations = true): static|array|null
    {
        $entityName = get_class($this);

        // GET
        if ($data === null) {
            $data = array_merge($this->_data, $this->_dataModified);

            foreach ($data as $k => &$v) {
                $v = $this->__get($k);
            }

            if ($loadRelations) {
                foreach (self::$relationFields[$entityName] as $relationField) {
                    $relation = $this->relation($relationField);

                    if ($relation instanceof Entity\Collection) {
                        $data[$relationField] = $relation->toArray();
                    }

                    if ($relation instanceof EntityInterface) {
                        $data[$relationField] = $relation->data(null, $modified, false);
                    }
                }
            }

            return $data;
        }

        // SET
        foreach ($data as $k => $v) {
            $this->set($k, $v, $modified);
        }

        return $this;
    }

    /**
     * Return array of field data with data from the field names listed removed.
     *
     * @param array<string> $except List of field names to exclude.
     *
     * @return array<string, mixed>
     */
    public function dataExcept(array $except): array
    {
        return array_diff_key((array) $this->data(), array_flip($except));
    }

    /**
     * Gets original data that has been modified since object construct.
     *
     * @return array<string, mixed>
     */
    public function dataOriginalModified(): array
    {
        $data     = [];
        $data_org = $this->_data_org;
        $data_mod = $this->_dataModified;

        if (!empty($data_org)) {
            foreach ($data_org as $field => $value) {
                if (isset($data_mod[$field]) && $data_mod[$field] != $value) {
                    $data[$field] = $data_mod[$field];
                }
            }
        }

        return $data;
    }

    /**
     * Gets data that has been modified since object construct,
     * optionally allowing for selecting a single field.
     *
     * @return array<string, mixed>|mixed|null
     */
    public function dataModified(?string $field = null): mixed
    {
        if ($field !== null) {
            return $this->_dataModified[$field] ?? null;
        }

        return $this->_dataModified;
    }

    /**
     * Gets data that has not been modified since object construct,
     * optionally allowing for selecting a single field.
     *
     * @return array<string, mixed>|mixed|null
     */
    public function dataUnmodified(?string $field = null): mixed
    {
        if ($field !== null) {
            return $this->_data[$field] ?? null;
        }

        return $this->_data;
    }

    /**
     * Is entity new (unsaved)?
     */
    public function isNew(?bool $new = null): bool
    {
        if ($new !== null) {
            $this->_isNew = $new;
        }

        return $this->_isNew;
    }

    /**
     * Returns true if a field has been modified.
     *
     * If no field name is passed in, returns whether any fields have changed.
     */
    public function isModified(?string $field = null): bool|null
    {
        if ($field !== null) {
            if (array_key_exists($field, $this->_dataModified)) {
                if ($this->_dataModified[$field] === null || ($this->_data[$field] ?? null) === null) {
                    // Use strict comparison for null values, non-strict otherwise
                    return $this->_dataModified[$field] !== ($this->_data[$field] ?? null);
                }

                return $this->_dataModified[$field] != ($this->_data[$field] ?? null);
            } elseif (array_key_exists($field, $this->_data)) {
                return false;
            } else {
                return null;
            }
        }

        foreach (array_keys($this->_dataModified) as $f) {
            if ($this->isModified($f) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Alias of self::data().
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return (array) $this->data(null, true);
    }

    /**
     * Check if any errors exist.
     */
    public function hasErrors(?string $field = null): bool
    {
        if ($field !== null) {
            return isset($this->_errors[$field]) && count($this->_errors[$field]) > 0;
        }

        return count($this->_errors) > 0;
    }

    /**
     * Error message getter/setter.
     *
     * - String:  return errors for that field key.
     * - Array:   set errors (replaces or merges depending on $overwrite).
     * - null:    return all errors.
     *
     * @param string|array<string, array<string>>|null $msgs
     *
     * @return array<string, array<string>>|array<string>
     */
    public function errors(mixed $msgs = null, bool $overwrite = true): array
    {
        if (is_string($msgs)) {
            return $this->_errors[$msgs] ?? [];
        }

        if (is_array($msgs)) {
            if ($overwrite) {
                $this->_errors = $msgs;
            } else {
                $this->_errors = array_merge_recursive($this->_errors, $msgs);
            }
        }

        return $this->_errors;
    }

    /**
     * Add an error to error messages array.
     *
     * @param string|array<string> $msg Error message text — string or array of messages.
     */
    public function error(string $field, string|array $msg): void
    {
        if (is_array($msg)) {
            foreach ($msg as $msgx) {
                $this->_errors[$field][] = $msgx;
            }
        } else {
            $this->_errors[$field][] = $msg;
        }
    }

    /**
     * Getter for field properties.
     */
    public function &__get(string $field): mixed
    {
        $v = null;

        $camelCaseField = str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));
        $getterMethod   = 'get' . $camelCaseField;

        if (!isset($this->_inGetter[$field]) && method_exists($this, $getterMethod)) {
            // Custom getter method
            $this->_inGetter[$field] = true;
            /** @var callable(): mixed $getter */
            $getter = [$this, $getterMethod];
            $v = $getter();
            unset($this->_inGetter[$field]);
        } else {
            // We can't use isset because it returns false for NULL values
            if (array_key_exists($field, $this->_dataModified)) {
                $v = & $this->_dataModified[$field];
            } elseif (array_key_exists($field, $this->_data)) {
                // If the value is an array or object, copy it to dataModified first
                // and return a reference to that
                if (is_array($this->_data[$field])) {
                    $this->_dataModified[$field] = $this->_data[$field];
                    $v = & $this->_dataModified[$field];
                } elseif (is_object($this->_data[$field])) {
                    $this->_dataModified[$field] = clone $this->_data[$field];
                    $v = & $this->_dataModified[$field];
                } else {
                    $v = & $this->_data[$field];
                }
            } elseif ($relation = $this->relation($field)) {
                $v = & $relation;
            }
        }

        return $v;
    }

    /**
     * Get a field value by name.
     */
    public function get(string $field): mixed
    {
        return $this->__get($field);
    }

    /**
     * Set a field value.
     */
    public function set(string $field, mixed $value, bool $modified = true): void
    {
        // Custom setter method
        $camelCaseField = str_replace(' ', '', ucwords(str_replace('_', ' ', $field)));
        $setterMethod   = 'set' . $camelCaseField;
        $entityName     = get_class($this);

        if (!isset($this->_inSetter[$field]) && method_exists($this, $setterMethod)) {
            $this->_inSetter[$field] = true;
            /** @var callable(mixed): mixed $setter */
            $setter = [$this, $setterMethod];
            $value = $setter($value);
            unset($this->_inSetter[$field]);
        }

        if (array_key_exists($field, $this->_data) || !in_array($field, self::$relationFields[$entityName] ?? [], true)) {
            if ($modified) {
                $this->_dataModified[$field] = $value;
            } else {
                $this->_data[$field] = $value;
            }
        } elseif (in_array($field, self::$relationFields[$entityName] ?? [], true)) {
            $this->relation($field, $value);
        }
    }

    /**
     * Get, set, or unset a loaded relation object on this entity.
     *
     * - Pass no $relationObj (or null) to GET the current relation value.
     * - Pass false to UNSET/clear the relation.
     * - Pass any other value to SET the relation.
     *
     * @param mixed $relationObj null=get, false=unset, anything else=set
     */
    public function relation(mixed $relationName, mixed $relationObj = null): mixed
    {
        // Local static property instead of class variable prevents the
        // relation object, mapper, and connection info from being printed
        // with a var_dump() of the entity.
        static $relations = [];
        $objectId = $this->_objectId;

        if ($relationObj === null) {
            // GET
            return $relations[$objectId][$relationName] ?? false;
        }

        if ($relationObj === false) {
            // UNSET
            if (isset($relations[$objectId][$relationName])) {
                unset($relations[$objectId][$relationName]);
            }

            return false;
        }

        // SET
        $relations[$objectId][$relationName] = $relationObj;

        $entityName = get_class($this);

        if (!isset(self::$relationFields[$entityName]) || !in_array($relationName, self::$relationFields[$entityName], true)) {
            self::$relationFields[$entityName][] = $relationName;
        }

        return $relations[$objectId][$relationName];
    }

    /**
     * Get primary key field name.
     */
    public function primaryKeyField(): string|false
    {
        foreach (static::fields() as $fieldName => $fieldAttrs) {
            if (isset($fieldAttrs['primary'])) {
                return $fieldName;
            }
        }

        return false;
    }

    /**
     * Get the value of the primary key field defined on this entity.
     */
    public function primaryKey(): mixed
    {
        $pkField = $this->primaryKeyField();

        return $pkField ? $this->get($pkField) : false;
    }

    /**
     * Helper so entity can be accessed via relation consistently without errors.
     *
     * @return $this
     */
    public function entity(): static
    {
        return $this;
    }

    /**
     * JsonSerializable.
     *
     * @inheritdoc
     */
    #[\Override]
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Set all field values to their defaults or null.
     */
    protected function initFields(): void
    {
        $fields = static::fields();

        foreach ($fields as $field => $opts) {
            if (!isset($this->_data[$field])) {
                $this->_data[$field] = $opts['value'] ?? ($opts['default'] ?? null);
            }
        }

        $entityName = get_class($this);

        if (!isset(self::$relationFields[$entityName])) {
            self::$relationFields[$entityName] = [];
        }
    }
}
