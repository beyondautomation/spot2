<?php

declare(strict_types=1);

namespace Spot;

/**
 * Entity object interface
 *
 * @package Spot
 */
interface EntityInterface
{
    /**
     * Enable isset() for object properties.
     */
    public function __isset(mixed $key): bool;

    /**
     * Setter for field properties.
     *
     * @param string $field
     * @param mixed  $value
     */
    public function __set($field, $value): void;

    /**
     * String representation of the class (JSON).
     */
    public function __toString(): string;

    /**
     * Table name getter/setter.
     */
    public static function table(?string $tableName = null): ?string;

    /**
     * Connection name getter/setter.
     */
    public static function connection(?string $connectionName = null): ?string;

    /**
     * Datasource options getter/setter.
     *
     * @param  array<string, mixed>|null $tableOpts
     * @return array<string, mixed>
     */
    public static function tableOptions(?array $tableOpts = null): array;

    /**
     * Mapper name getter.
     */
    public static function mapper(): string|false;

    /**
     * Return defined fields of the entity.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields(): array;

    /**
     * Add events to this entity.
     */
    public static function events(EventEmitter $eventEmitter): void;

    /**
     * Return defined relations of the entity.
     *
     * @return array<string, mixed>
     */
    public static function relations(MapperInterface $mapper, EntityInterface $entity): array;

    /**
     * Return scopes defined by this entity.
     *
     * @return array<string, callable>
     */
    public static function scopes(): array;

    /**
     * Gets and sets data on the current entity.
     *
     * @param  array<string, mixed>|null $data
     * @return static|array<string, mixed>|null
     */
    public function data(?array $data = null, bool $modified = true, bool $loadRelations = true): static|array|null;

    /**
     * Return array of field data with data from the field names listed removed.
     *
     * @param  array<string>       $except
     * @return array<string, mixed>
     */
    public function dataExcept(array $except): array;

    /**
     * Gets data that has been modified since object construct.
     *
     * @return array<string, mixed>|mixed|null
     */
    public function dataModified(?string $field = null): mixed;

    /**
     * Gets data that has not been modified since object construct.
     *
     * @return array<string, mixed>|mixed|null
     */
    public function dataUnmodified(?string $field = null): mixed;

    /**
     * Is entity new (unsaved)?
     */
    public function isNew(?bool $new = null): bool;

    /**
     * Returns true if a field has been modified.
     *
     * If no field name is passed, returns whether any fields have changed.
     */
    public function isModified(?string $field = null): bool|null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Check if any errors exist.
     */
    public function hasErrors(?string $field = null): bool;

    /**
     * Error message getter/setter.
     *
     * @param  string|array<string, array<string>>|null $msgs
     * @return array<string, array<string>>|array<string>
     */
    public function errors(mixed $msgs = null, bool $overwrite = true): array;

    /**
     * Add an error to error messages array.
     *
     * @param string|array<string> $msg
     */
    public function error(string $field, string|array $msg): void;

    /**
     * Getter for field properties.
     */
    public function &__get(string $field): mixed;

    /**
     * Get a field value by name.
     */
    public function get(string $field): mixed;

    /**
     * Set a field value.
     */
    public function set(string $field, mixed $value, bool $modified = true): void;

    /**
     * Get, set, or unset a loaded relation object on this entity.
     */
    public function relation(mixed $relationName, mixed $relationObj = null): mixed;

    /**
     * Get primary key field name.
     */
    public function primaryKeyField(): string|false;

    /**
     * Get the value of the primary key field.
     */
    public function primaryKey(): mixed;

    /**
     * JsonSerializable.
     */
    public function jsonSerialize(): mixed;
}
