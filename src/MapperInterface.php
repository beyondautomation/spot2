<?php

declare(strict_types=1);

namespace Spot;

use Doctrine\DBAL\Connection;

/**
 * Base DataMapper Interface
 *
 * @package Spot
 */
interface MapperInterface
{
    public function __construct(Locator $locator, string $entityName);

    public function config(): Config;

    public function getMapper(string $entityName): Mapper;

    public function entity(): string;

    public function queryClass(): string;

    public function collectionClass(): string;

    public function entityManager(): Entity\Manager;

    public function eventEmitter(): EventEmitter;

    public function loadEvents(): void;

    public function loadRelations(EntityInterface $entity): void;

    public function hasMany(EntityInterface $entity, string $entityName, string $foreignKey, mixed $localValue = null): Relation\HasMany;

    public function hasManyThrough(EntityInterface $entity, string $hasManyEntity, string $throughEntity, string $selectField, string $whereField): Relation\HasManyThrough;

    public function hasOne(EntityInterface $entity, string $foreignEntity, string $foreignKey): Relation\HasOne;

    public function belongsTo(EntityInterface $entity, string $foreignEntity, string $localKey): Relation\BelongsTo;

    public function prepareEntity(EntityInterface $entity): bool|null;

    public function resolver(): Query\Resolver;

    public function table(): string;

    /** @return array<string, array<string, mixed>> */
    public function fields(): array;

    /** @return array<string, array<string, mixed>> */
    public function fieldsDefined(): array;

    /** @return array<string, mixed> */
    public function relations(): array;

    /** @return array<string, callable> */
    public function scopes(): array;

    public function primaryKey(EntityInterface $entity): mixed;

    public function primaryKeyField(): string;

    public function fieldExists(string $field): bool;

    /** @return array<string, mixed>|false */
    public function fieldInfo(string $field): array|false;

    public function fieldType(string $field): string|false;

    /**
     * @param string|null $connectionName Optional named connection.
     *
     * @throws Exception
     */
    public function connection(?string $connectionName = null): Connection;

    public function connectionIs(string $type): bool;

    /**
     * @param iterable<array<string, mixed>> $cursor
     * @param array<string>                  $with
     */
    public function collection(iterable $cursor, array $with = []): Entity\Collection;

    public function get(mixed $identifier = false): EntityInterface|false;

    /** @param array<string, mixed> $data */
    public function build(array $data): EntityInterface;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function create(array $data, array $options = []): EntityInterface;

    /** @param array<mixed> $params */
    public function query(string $sql, array $params = []): Entity\Collection;

    /** @param array<mixed> $params */
    public function exec(string $sql, array $params = []): int;

    public function all(): Query;

    /** @param array<string, mixed> $conditions */
    public function where(array $conditions = []): Query;

    /** @param array<string, mixed> $conditions */
    public function first(array $conditions = []): EntityInterface|false;

    public function queryBuilder(): Query;

    public function select(string $fields = '*'): Query;

    /** @param array<string, mixed> $options */
    public function save(EntityInterface $entity, array $options = []): mixed;

    /**
     * @param EntityInterface|array<string, mixed> $entity
     * @param array<string, mixed>                 $options
     */
    public function insert(EntityInterface|array $entity, array $options = []): mixed;

    /** @param array<string, mixed> $options */
    public function update(EntityInterface $entity, array $options = []): mixed;

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function upsert(array $data, array $where): EntityInterface;

    /** @param EntityInterface|array<string, mixed> $conditions */
    public function delete(EntityInterface|array $conditions = []): mixed;

    public function transaction(\Closure $work, ?string $connectionName = null): mixed;

    public function truncateTable(bool $cascade = false): int;

    public function dropTable(): bool;

    public function migrate(): bool|int;

    /** @param array<string, mixed> $options */
    public function validate(EntityInterface $entity, array $options = []): bool;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function convertToDatabaseValues(string $entityName, array $data): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function convertToPHPValues(string $entityName, array $data): array;

    /** @param array<string, mixed> $options */
    public function saveHasRelations(EntityInterface $entity, array $options = []): mixed;

    /** @param array<string, mixed> $options */
    public function saveBelongsToRelations(EntityInterface $entity, array $options = []): mixed;
}
