<?php

declare(strict_types=1);

namespace SpotTest\Entity;

use Spot\Entity;
use Spot\EntityInterface;
use Spot\MapperInterface;

/**
 * CircularA → CircularB → CircularA (circular FK chain).
 * Tests that migrate() does not recurse infinitely.
 */
class CircularA extends Entity
{
    protected static ?string $table = 'test_circular_a';

    #[\Override]
    public static function fields(): array
    {
        return [
            'id'  => ['type' => 'integer', 'autoincrement' => true, 'primary' => true],
            'b_id' => ['type' => 'integer', 'unsigned' => true, 'index' => true],
            'name' => ['type' => 'string', 'length' => 64],
        ];
    }

    #[\Override]
    public static function relations(MapperInterface $mapper, EntityInterface $entity): array
    {
        return [
            'b' => $mapper->belongsTo($entity, CircularB::class, 'id', 'b_id'),
        ];
    }
}
