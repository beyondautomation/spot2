<?php

declare(strict_types=1);

namespace SpotTest\Entity;

use Spot\Entity;
use Spot\EntityInterface;
use Spot\MapperInterface;

/**
 * CircularB → CircularA (completing the circle).
 */
class CircularB extends Entity
{
    protected static ?string $table = 'test_circular_b';

    #[\Override]
    public static function fields(): array
    {
        return [
            'id'   => ['type' => 'integer', 'autoincrement' => true, 'primary' => true],
            'a_id' => ['type' => 'integer', 'unsigned' => true, 'index' => true],
            'name' => ['type' => 'string', 'length' => 64],
        ];
    }

    #[\Override]
    public static function relations(MapperInterface $mapper, EntityInterface $entity): array
    {
        return [
            'a' => $mapper->belongsTo($entity, CircularA::class, 'id', 'a_id'),
        ];
    }
}
