<?php

declare(strict_types=1);

namespace Spot\Relation;

use Spot\Entity\Collection;
use Spot\EntityInterface;
use Spot\Mapper;
use Spot\Query;

/**
 * Abstract base class for all Spot relation objects.
 *
 * @package Spot
 */
abstract class RelationAbstract
{
    protected Mapper $mapper;

    protected string $entityName;

    protected string $foreignKey;

    protected string $localKey;

    protected mixed $identityValue = null;

    protected ?Query $query = null;

    /** @var array<callable> */
    protected array $queryQueue = [];

    protected mixed $result = null;

    /**
     * Passthrough for missing methods — delegates to Query then to the result object.
     *
     * When Mapper::$loadingRelations is true (i.e. we are inside loadRelations()
     * hydrating entities from the database), all query-modifier calls such as
     * ->where(), ->order(), ->limit(), and ->with() are silently ignored.
     * This prevents hundreds of closure objects being allocated per entity when
     * models define complex query chains in their relations() method.
     *
     * @param array<mixed> $args
     */
    public function __call(string $func, array $args): mixed
    {
        $scopes = $this->mapper()->getMapper($this->entityName())->scopes();

        if (method_exists(Query::class, $func) || array_key_exists($func, $scopes)) {
            // Skip queuing query modifiers during entity hydration — no closures,
            // no memory accumulation, no sub-relation cascades.
            if (!\Spot\Mapper::$loadingRelations) {
                $this->queryQueue[] = function (Query $query) use ($func, $args): Query {
                    /** @var callable(mixed...): Query $callable */
                    $callable = [$query, $func];

                    return $callable(...$args);
                };
            }

            return $this;
        }

        $result = $this->execute();

        if ($result && method_exists($result, $func)) {
            /** @var callable(mixed...): mixed $callable */
            $callable = [$result, $func];

            return $callable(...$args);
        }

        throw new \BadMethodCallException('Method ' . static::class . "::$func does not exist");
    }

    public function mapper(): Mapper
    {
        return $this->mapper;
    }

    public function entityName(): string
    {
        return $this->entityName;
    }

    public function foreignKey(): string
    {
        return $this->foreignKey;
    }

    public function localKey(): string
    {
        return $this->localKey;
    }

    public function entityKey(): string
    {
        return $this->mapper()->primaryKeyField();
    }

    /**
     * Get or set the identity value used to constrain relation queries.
     */
    public function identityValue(mixed $identityValue = null): mixed
    {
        if ($identityValue !== null) {
            $this->identityValue = $identityValue;
        }

        return $this->identityValue;
    }

    /**
     * Set identity values from a collection of parent entities.
     */
    abstract public function identityValuesFromCollection(Collection $collection): void;

    /**
     * Map relation results back onto each entity in the given collection.
     */
    public function eagerLoadOnCollection(string $relationName, Collection $collection): Collection
    {
        $this->identityValuesFromCollection($collection);
        $relationForeignKey = $this->foreignKey();
        $relationEntityKey  = $this->entityKey();
        $collectionRelations = $this->query();

        $entityRelations = [];

        foreach ($collectionRelations as $relatedEntity) {
            $entityRelations[$relatedEntity->$relationForeignKey] = $relatedEntity;
        }

        foreach ($collection as $entity) {
            $key = $entity->$relationEntityKey !== null ? $entity->$relationEntityKey : '';

            if (isset($entityRelations[$key])) {
                $entity->relation($relationName, $entityRelations[$key]);
            } else {
                $entity->relation($relationName, null);
            }
        }

        return $collection;
    }

    public function query(): Query
    {
        if ($this->query === null) {
            $this->query = $this->buildQuery();

            foreach ($this->queryQueue as $closure) {
                $result = call_user_func($closure, $this->query);

                if ($result instanceof Query) {
                    $this->query = $result;
                }
            }
        }

        return $this->query;
    }

    /**
     * Execute the relation query and cache the result.
     */
    public function execute(): mixed
    {
        if ($this->result === null) {
            $this->result = $this->query()->execute();
        }

        return $this->result;
    }

    /**
     * Save related entities.
     *
     * @param EntityInterface $entity       Entity to save relation from.
     * @param string          $relationName Name of the relation.
     * @param array<mixed>    $options      Options passed to child mappers.
     */
    abstract public function save(EntityInterface $entity, string $relationName, array $options = []): mixed;

    /**
     * Build the underlying query object for this relation.
     */
    abstract protected function buildQuery(): Query;
}
