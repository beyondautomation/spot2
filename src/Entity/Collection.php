<?php

declare(strict_types=1);

namespace Spot\Entity;

use Spot\Entity;
use Spot\EntityInterface;

/**
 * Collection of Spot Entity objects.
 *
 * Implements Iterator, Countable, ArrayAccess, and JsonSerializable so that
 * collections can be used directly in foreach loops, count(), and json_encode().
 *
 * @package Spot\Entity
 *
 * @author  Vance Lucas <vance@vancelucas.com>
 *
 * @implements \Iterator<int, EntityInterface>
 * @implements \ArrayAccess<int, EntityInterface>
 */
class Collection implements \Iterator, \Countable, \ArrayAccess, \JsonSerializable
{
    /**
     * The entity objects in this collection.
     *
     * @var array<EntityInterface>
     */
    protected array $results = [];

    /**
     * Primary key values for all entities in the collection.
     *
     * @var array<mixed>
     */
    protected array $resultsIdentities = [];

    /** @var string|null Fully-qualified entity class name. */
    protected ?string $entityName = null;

    /**
     * @param array<EntityInterface> $results           Pre-loaded entity objects.
     * @param array<mixed>           $resultsIdentities Primary key values.
     * @param string|null            $entityName        Entity class name.
     */
    public function __construct(
        array $results = [],
        array $resultsIdentities = [],
        ?string $entityName = null,
    ) {
        $this->results = $results;
        $this->resultsIdentities = $resultsIdentities;
        $this->entityName = $entityName;
    }

    /**
     * String representation — e.g. "Spot\Entity\Collection[42]".
     */
    public function __toString(): string
    {
        return __CLASS__ . '[' . $this->count() . ']';
    }

    /**
     * Return the primary key values of all entities in this collection.
     *
     * @return array<mixed>
     */
    public function resultsIdentities(): array
    {
        return $this->resultsIdentities;
    }

    /**
     * Return the entity class name this collection holds.
     */
    public function entityName(): ?string
    {
        return $this->entityName;
    }

    /**
     * Return the first entity in the collection, or false if empty.
     */
    public function first(): EntityInterface|false
    {
        $this->rewind();

        return $this->valid() ? $this->current() : false;
    }

    /**
     * Append a single entity to the collection.
     */
    public function add(EntityInterface $entity): void
    {
        $this->results[] = $entity;
    }

    /**
     * Return the raw array of entity objects.
     *
     * @return array<EntityInterface>
     */
    public function entities(): array
    {
        return $this->results;
    }

    /**
     * Merge another collection's entities into this one.
     *
     * By default only entities not already present (by array comparison) are added.
     *
     * @param Collection $collection The collection to merge in.
     * @param bool       $onlyUnique Skip entities already present (default true).
     *
     * @todo Implement faster uniqueness checking via primary key hash.
     */
    public function merge(Collection $collection, bool $onlyUnique = true): static
    {
        $collectionData = $this->toArray();

        foreach ($collection as $entity) {
            if ($onlyUnique && in_array($entity->toArray(), $collectionData, true)) {
                continue;
            }
            $this->add($entity);
        }

        return $this;
    }

    /**
     * Return an array representation of the collection.
     *
     * - No arguments: array of entity arrays (via toArray() on each).
     * - $keyColumn only: flat array of that column's values.
     * - Both columns: associative array keyed by $keyColumn, values from $valueColumn.
     *
     * @param string|null $keyColumn   Column to use as array keys.
     * @param string|null $valueColumn Column to use as array values.
     *
     * @return array<mixed>
     */
    public function toArray(?string $keyColumn = null, ?string $valueColumn = null): array
    {
        $return = [];

        if ($keyColumn === null && $valueColumn === null) {
            foreach ($this->results as $row) {
                $return[] = $row->toArray();
            }
        } elseif ($keyColumn !== null && $valueColumn === null) {
            foreach ($this->results as $row) {
                $return[] = $row->$keyColumn;
            }
        } else {
            foreach ($this->results as $row) {
                $return[$row->$keyColumn] = $row->$valueColumn;
            }
        }

        return $return;
    }

    /**
     * JsonSerializable — returns the same structure as toArray().
     */
    #[\Override]
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Run a callback with the raw results array as its argument.
     */
    public function run(callable $callback): mixed
    {
        return call_user_func_array($callback, [$this->results]);
    }

    /**
     * Apply a transform function to every entity and return the results.
     *
     * @param callable $func Function receiving an entity, returning any value.
     *
     * @return array<mixed>
     */
    public function map(callable $func): array
    {
        $ret = [];

        foreach ($this as $obj) {
            $ret[] = $func($obj);
        }

        return $ret;
    }

    /**
     * Return a new Collection containing only entities for which $func returns true.
     *
     * @param callable $func Predicate function receiving an entity.
     */
    public function filter(callable $func): static
    {
        $ret = new static();

        foreach ($this as $obj) {
            if ($func($obj)) {
                $ret->add($obj);
            }
        }

        return $ret;
    }

    // =========================================================================
    // SPL Countable
    // =========================================================================

    /** @inheritdoc */
    #[\Override]
    public function count(): int
    {
        return count($this->results);
    }

    // =========================================================================
    // SPL Iterator
    // =========================================================================

    /** @inheritdoc */
    #[\Override]
    public function current(): EntityInterface
    {
        $value = current($this->results);
        assert($value instanceof EntityInterface, 'current() called on empty or exhausted Collection');

        return $value;
    }

    /** @inheritdoc */
    #[\Override]
    public function key(): int
    {
        return (int) key($this->results);
    }

    /** @inheritdoc */
    #[\Override]
    public function next(): void
    {
        next($this->results);
    }

    /** @inheritdoc */
    #[\Override]
    public function rewind(): void
    {
        reset($this->results);
    }

    /** @inheritdoc */
    #[\Override]
    public function valid(): bool
    {
        return current($this->results) !== false;
    }

    // =========================================================================
    // SPL ArrayAccess
    // =========================================================================

    /** @inheritdoc */
    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->results[$offset]);
    }

    /** @inheritdoc */
    #[\Override]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->results[$offset];
    }

    /** @inheritdoc */
    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->results[] = $value;
        } else {
            $this->results[$offset] = $value;
        }
    }

    /** @inheritdoc */
    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        if (is_int($offset)) {
            array_splice($this->results, $offset, 1);
        } else {
            unset($this->results[$offset]);
        }
    }
}
