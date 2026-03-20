<?php

declare(strict_types=1);

namespace SpotTest\Entity;

use Spot\Entity;
use Spot\EntityInterface;
use Spot\MapperInterface;

/**
 * RecursiveEntity
 *
 * @package Spot
 */
class RecursiveEntity extends Entity
{
    protected static ?string $table = 'test_recursive';

    #[\Override]
    public static function fields(): array
    {
        return [
            'id' => [
                'type' => 'integer', 'primary' => true, 'autoincrement' => true,
                'form' => false,
            ],
            'priority' => [
                'type' => 'integer', 'index' => true,
                'form' => false,
            ],
            'status' => [
                'type' => 'smallint', 'required' => true, 'default' => 1,
                'options' => [1, 0],
            ],
            'date_publish' => [
                'type' => 'date',
            ],
            'name' => [
                'type' => 'string', 'required' => true, 'label' => true,
                'validation' => ['lengthMax' => 255],
            ],
            'description' => [
                'type' => 'text',
            ],
            'parent_id' => [
                'type' => 'integer', 'index' => true,
            ],
            'siblingId' => [
                'type' => 'integer', 'index' => true, 'column' => 'sibling_id',
            ],
        ];
    }

    #[\Override]
    public static function relations(MapperInterface $mapper, EntityInterface $entity): array
    {
        return [
            'children' => $mapper->hasMany($entity, \SpotTest\Entity\RecursiveEntity::class, 'parent_id'),
            'parent' => $mapper->belongsTo($entity, \SpotTest\Entity\RecursiveEntity::class, 'parent_id'),
            'my_sibling' => $mapper->belongsTo($entity, \SpotTest\Entity\RecursiveEntity::class, 'siblingId'),
            'sibling' => $mapper->hasOne($entity, \SpotTest\Entity\RecursiveEntity::class, 'siblingId'),
        ];
    }
}
