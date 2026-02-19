<?php

declare(strict_types=1);

namespace Spot\Relation;

use Spot\Entity\Collection;
use Spot\EntityInterface;
use Spot\Mapper;

/**
 * BelongsTo relation — the local entity holds the foreign key pointing to the related entity.
 *
 * @package Spot
 *
 * @implements \ArrayAccess<string, mixed>
 */
class BelongsTo extends RelationAbstract implements \ArrayAccess
{
    public function __construct(
        Mapper $mapper,
        string $entityName,
        string $foreignKey,
        string $localKey,
        mixed $identityValue,
    ) {
        $this->mapper        = $mapper;
        $this->entityName    = $entityName;
        $this->foreignKey    = $foreignKey;
        $this->localKey      = $localKey;
        $this->identityValue = $identityValue;
    }

    // Magic passthrough
    public function __get(string $key): mixed
    {
        $entity = $this->execute();

        if ($entity) {
            return $entity->$key;
        }

        return null;
    }

    public function __set(string $key, mixed $val): void
    {
        $this->execute()->$key = $val;
    }

    #[\Override]
    public function identityValuesFromCollection(Collection $collection): void
    {
        $this->identityValue($collection->toArray($this->localKey()));
    }

    /**
     * Eager-load this BelongsTo relation onto a collection of parent entities.
     *
     * The base class implementation is designed for HasMany (wrong key direction).
     * For BelongsTo (e.g. template_page.page_id -> pages.id):
     *   - We fetch all related entities WHERE pk IN (list of FK values from parents)
     *   - Index results by their PRIMARY KEY (pages.id)
     *   - For each parent, look up by its FK value (template_page.page_id)
     */
    #[\Override]
    public function eagerLoadOnCollection(string $relationName, Collection $collection): Collection
    {
        $this->identityValuesFromCollection($collection);

        $relatedMapper  = $this->mapper()->getMapper($this->entityName());
        $relatedPkField = $relatedMapper->primaryKeyField();
        $localKeyField  = $this->localKey();   // FK on parent entity (e.g. page_id)

        // Fetch all related entities in one query
        $relatedEntities = [];
        foreach ($this->query() as $relatedEntity) {
            // Index by the related entity's primary key
            $relatedEntities[$relatedEntity->$relatedPkField] = $relatedEntity;
        }

        // Map back onto each parent entity
        foreach ($collection as $entity) {
            $fkValue = $entity->$localKeyField;

            if ($fkValue !== null && isset($relatedEntities[$fkValue])) {
                $entity->relation($relationName, $relatedEntities[$fkValue]);
            } else {
                $entity->relation($relationName, null);
            }
        }

        return $collection;
    }

    /**
     * For BelongsTo, the entity key is the local key (not the primary key).
     */
    #[\Override]
    public function entityKey(): string
    {
        return $this->localKey();
    }

    #[\Override]
    public function execute(): mixed
    {
        if ($this->result === null) {
            $collection   = $this->query()->execute();
            $this->result = $collection !== false ? $collection->first() : false;
        }

        return $this->result;
    }

    public function entity(): mixed
    {
        return $this->execute();
    }

    /**
     * @param array<mixed> $options
     */
    #[\Override]
    public function save(EntityInterface $entity, string $relationName, array $options = []): mixed
    {
        $lastResult    = 0;
        $relatedEntity = $entity->relation($relationName);

        if ($relatedEntity instanceof EntityInterface) {
            if ($relatedEntity->isNew() || $relatedEntity->isModified()) {
                $relatedMapper = $this->mapper()->getMapper($this->entityName());
                $lastResult    = $relatedMapper->save($relatedEntity, $options);

                if ($entity->get($this->localKey()) !== $relatedEntity->primaryKey()) {
                    $relatedRelations = $entity->relations($relatedMapper, $relatedEntity);

                    foreach ($relatedRelations as $relatedRelation) {
                        if ($relatedRelation instanceof HasOne && $relatedRelation->foreignKey() === $this->localKey()) {
                            if ($relatedMapper->entityManager()->fields()[$relatedRelation->foreignKey()]['notnull']) {
                                $lastResult = $relatedMapper->delete([$relatedRelation->foreignKey() => $entity->get($relatedRelation->foreignKey())]);
                            } else {
                                $relatedMapper->queryBuilder()->builder()
                                    ->update($relatedMapper->table())
                                    ->set($relatedRelation->foreignKey(), null) // @phpstan-ignore-line argument.type
                                    ->where([$relatedRelation->foreignKey() => $entity->get($relatedRelation->foreignKey())]); // @phpstan-ignore-line argument.type
                            }
                        }
                    }
                    $entity->set($this->localKey(), $relatedEntity->primaryKey());
                }
            }
        }

        return $lastResult;
    }

    // ArrayAccess
    public function offsetExists(mixed $offset): bool
    {
        $entity = $this->execute();

        return isset($entity->$offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $entity = $this->execute();

        return $entity->$offset;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $entity = $this->execute();

        if ($offset === null) {
            $entity[] = $value;
        } else {
            $entity->$offset = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $entity = $this->execute();
        unset($entity->$offset);
    }

    protected function buildQuery(): \Spot\Query
    {
        $foreignMapper = $this->mapper()->getMapper($this->entityName());

        return $foreignMapper->where([$this->foreignKey() => $this->identityValue()]);
    }
}
