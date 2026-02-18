<?php

declare(strict_types=1);

namespace Spot\Relation;

use Spot\Entity\Collection;
use Spot\EntityInterface;
use Spot\Mapper;

/**
 * HasOne relation — the foreign entity holds the key back to the local entity.
 *
 * @package Spot
 *
 * @implements \ArrayAccess<string, mixed>
 */
class HasOne extends RelationAbstract implements \ArrayAccess
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
        $this->identityValue($collection->resultsIdentities());
    }

    #[\Override]
    public function execute(): mixed
    {
        if ($this->result === null) {
            $collection    = $this->query()->execute();
            $this->result  = $collection !== false ? $collection->first() : false;
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
        $lastResult    = false;
        $relatedEntity = $entity->relation($relationName);
        $relatedMapper = $this->mapper()->getMapper($this->entityName());

        // Already an autoloaded relation object — skip
        if ($relatedEntity instanceof HasOne) {
            return 0;
        }

        if ($relatedEntity === false || ($relatedEntity instanceof EntityInterface && $relatedEntity->get($this->foreignKey()) !== $entity->primaryKey())) {
            if ($relatedMapper->entityManager()->fields()[$this->foreignKey()]['notnull']) {
                $relatedMapper->delete([$this->foreignKey() => $entity->primaryKey()]);
            } else {
                $relatedMapper->queryBuilder()->builder()
                    ->update($relatedMapper->table())
                    ->set($this->foreignKey(), null) // @phpstan-ignore-line argument.type
                    ->where([$this->foreignKey() => $entity->primaryKey()]); // @phpstan-ignore-line argument.type
            }

            if ($relatedEntity instanceof EntityInterface) {
                $relatedEntity->set($this->foreignKey(), $entity->primaryKey());
            }
        }

        if ($relatedEntity instanceof EntityInterface && ($relatedEntity->isNew() || $relatedEntity->isModified())) {
            $lastResult = $relatedMapper->save($relatedEntity, $options);
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

        return $entity->$offset;  // fixed: was $key (undefined var)
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
