<?php

declare(strict_types=1);

namespace Spot\Relation;

use Spot\Entity;
use Spot\Entity\Collection;
use Spot\EntityInterface;
use Spot\Mapper;

/**
 * HasMany relation — one parent entity has many related entities via a foreign key.
 *
 * @package Spot
 *
 * @implements \IteratorAggregate<int, EntityInterface>
 * @implements \ArrayAccess<int, EntityInterface>
 */
class HasMany extends RelationAbstract implements \Countable, \IteratorAggregate, \ArrayAccess
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

    #[\Override]
    public function identityValuesFromCollection(Collection $collection): void
    {
        $this->identityValue($collection->resultsIdentities());
    }

    /**
     * Map results back to the parent collection (eager loading).
     */
    #[\Override]
    public function eagerLoadOnCollection(string $relationName, Collection $collection): Collection
    {
        $this->identityValuesFromCollection($collection);
        $relationForeignKey = $this->foreignKey();
        $relationEntityKey  = $this->entityKey();
        $collectionRelations = $this->query();
        $collectionClass     = $this->mapper()->getMapper($this->entityName())->collectionClass();

        $entityRelations = [];

        foreach ($collectionRelations as $relatedEntity) {
            $entityRelations[$relatedEntity->$relationForeignKey][] = $relatedEntity;
        }

        foreach ($collection as $entity) {
            if (isset($entityRelations[$entity->$relationEntityKey])) {
                $entityCollection = new $collectionClass($entityRelations[$entity->$relationEntityKey]);
                $entity->relation($relationName, $entityCollection);
            } else {
                $entity->relation($relationName, new $collectionClass());
            }
        }

        return $collection;
    }

    /**
     * @param array<mixed> $options
     */
    #[\Override]
    public function save(EntityInterface $entity, string $relationName, array $options = []): mixed
    {
        $relatedEntities = $entity->relation($relationName);
        $deletedIds      = [];
        $lastResult      = false;
        $relatedMapper   = $this->mapper()->getMapper($this->entityName());

        if (is_array($relatedEntities) || $relatedEntities instanceof Entity\Collection) {
            $oldEntities = $this->execute();
            $relatedIds  = [];

            foreach ($relatedEntities as $related) {
                if ($related->isNew() || $related->isModified() || $related->get($this->foreignKey()) !== $entity->primaryKey()) {
                    $related->set($this->foreignKey(), $entity->primaryKey());
                    $lastResult = $relatedMapper->save($related, $options);
                }
                $relatedIds[] = $related->id;
            }

            foreach ($oldEntities as $oldRelatedEntity) {
                if (!in_array($oldRelatedEntity->primaryKey(), $relatedIds)) {
                    $deletedIds[] = $oldRelatedEntity->primaryKey();
                }
            }
        }

        if (count($deletedIds) || $relatedEntities === false) {
            $conditions = [$this->foreignKey() => $entity->primaryKey()];

            if (count($deletedIds)) {
                $conditions[$this->localKey() . ' :in'] = $deletedIds;
            }

            if ($relatedMapper->entityManager()->fields()[$this->foreignKey()]['notnull']) {
                $relatedMapper->delete($conditions);
            } else {
                // Null out the FK rather than deleting — not executed inline; caller must flush.
                $relatedMapper->queryBuilder()->builder()
                    ->update($relatedMapper->table())
                    ->set($this->foreignKey(), null) // @phpstan-ignore-line argument.type
                    ->where($conditions); // @phpstan-ignore-line argument.type
            }
        }

        return $lastResult;
    }

    public function count(): int
    {
        if ($this->result === null) {
            return $this->query()->count();
        }

        return count($this->result);
    }

    public function getIterator(): \Traversable
    {
        $data = $this->execute();

        return $data ?: new \ArrayIterator([]);
    }

    public function offsetExists(mixed $offset): bool
    {
        $this->execute();

        return isset($this->result[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $this->execute();

        return $this->result[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->execute();

        if ($offset === null) {
            $this->result[] = $value;
        } else {
            $this->result[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->execute();
        unset($this->result[$offset]);
    }

    protected function buildQuery(): \Spot\Query
    {
        $foreignMapper = $this->mapper()->getMapper($this->entityName());

        return $foreignMapper->where([$this->foreignKey() => $this->identityValue()]);
    }
}
