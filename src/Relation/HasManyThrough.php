<?php

declare(strict_types=1);

namespace Spot\Relation;

use Spot\Entity;
use Spot\Entity\Collection;
use Spot\EntityInterface;
use Spot\Mapper;

/**
 * HasManyThrough relation — many-to-many via a pivot/through table.
 *
 * @package Spot
 *
 * @implements \IteratorAggregate<int, EntityInterface>
 * @implements \ArrayAccess<int, EntityInterface>
 */
class HasManyThrough extends RelationAbstract implements \Countable, \IteratorAggregate, \ArrayAccess
{
    protected string $throughEntityName;

    protected ?Collection $throughCollection = null;

    public function __construct(
        Mapper $mapper,
        string $entityName,
        string $throughEntityName,
        string $foreignKey,
        string $localKey,
        mixed $identityValue,
    ) {
        $this->mapper            = $mapper;
        $this->entityName        = $entityName;
        $this->throughEntityName = $throughEntityName;
        $this->foreignKey        = $foreignKey;
        $this->localKey          = $localKey;
        $this->identityValue     = $identityValue;
    }

    #[\Override]
    public function identityValuesFromCollection(Collection $collection): void
    {
        $this->identityValue($collection->resultsIdentities());
    }

    public function throughEntityName(): string
    {
        return $this->throughEntityName;
    }

    /**
     */
    #[\Override]
    public function eagerLoadOnCollection(string $relationName, Collection $collection): Collection
    {
        $this->identityValuesFromCollection($collection);
        $relationForeignKey        = $this->foreignKey();
        $relationLocalKey          = $this->localKey();
        $relationEntityKey         = $this->entityKey();
        $relatedMapper             = $this->mapper()->getMapper($this->entityName());
        $relationRelatedForeignKey = $relatedMapper->primaryKeyField();
        $collectionRelationsRaw    = $this->query()->execute();
        $collectionRelations       = $collectionRelationsRaw !== false ? $collectionRelationsRaw : new Collection();
        $collectionClass           = $relatedMapper->collectionClass();

        $entityRelations = [];

        if ($this->throughCollection !== null) {
            foreach ($this->throughCollection as $throughEntity) {
                $throughForeignKey = $throughEntity->$relationForeignKey;
                $throughLocalKey   = $throughEntity->$relationLocalKey;

                foreach ($collectionRelations as $relatedEntity) {
                    if ($relatedEntity->$relationRelatedForeignKey == $throughForeignKey) {
                        $entityRelations[$throughLocalKey][] = $relatedEntity;
                    }
                }
            }
        }

        foreach ($collection as $entity) {
            if (isset($entityRelations[$entity->$relationEntityKey])) {
                /** @var array<\Spot\EntityInterface> $relatedItems */
                $relatedItems     = $entityRelations[$entity->$relationEntityKey];
                $entityCollection = new $collectionClass($relatedItems);
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
        $deletedIds      = [];
        $lastResult      = false;
        $relatedEntities = $entity->relation($relationName);
        $oldEntities     = $this->execute();

        if (is_array($relatedEntities) || $relatedEntities instanceof Entity\Collection) {
            $throughMapper = $this->mapper()->getMapper($this->throughEntityName());
            $relatedMapper = $this->mapper()->getMapper($this->entityName());
            $relatedIds    = [];

            foreach ($relatedEntities as $related) {
                if ($related->isNew() || $related->isModified()) {
                    $lastResult = $relatedMapper->save($related, $options);
                }
                $relatedIds[] = $related->primaryKey();

                if (!count($throughMapper->where([$this->localKey() => $entity->primaryKey(), $this->foreignKey() => $related->primaryKey()]))) {
                    $lastResult = $throughMapper->create([$this->localKey() => $entity->primaryKey(), $this->foreignKey() => $related->primaryKey()]);
                }
            }

            foreach ($oldEntities as $oldRelatedEntity) {
                if (!in_array($oldRelatedEntity->primaryKey(), $relatedIds)) {
                    $deletedIds[] = $oldRelatedEntity->primaryKey();
                }
            }

            if (!empty($deletedIds)) {
                $throughMapper->delete([$this->localKey() => $entity->primaryKey(), $this->foreignKey() . ' :in' => $deletedIds]);
            }
        } elseif ($relatedEntities === false) {
            $throughMapper = $this->mapper()->getMapper($this->throughEntityName());
            $throughMapper->delete([$this->localKey() => $entity->primaryKey()]);
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

    public function offsetGet(mixed $key): mixed
    {
        $this->execute();

        return $this->result[$key];
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
        $selectField = $this->foreignKey();
        $whereField  = $this->localKey();

        $hasManyMapper  = $this->mapper()->getMapper($this->entityName());
        $hasManyPkField = $hasManyMapper->primaryKeyField();
        $throughMapper  = $this->mapper()->getMapper($this->throughEntityName());
        $throughQuery   = $throughMapper->select()->where([$whereField => $this->identityValue()]);

        $throughResult           = $throughQuery->execute();
        $this->throughCollection = $throughResult !== false ? $throughResult : new Collection();
        $throughEntityIds        = $this->throughCollection->toArray($selectField);

        return $hasManyMapper->select()->where([$hasManyPkField => $throughEntityIds]);
    }
}
