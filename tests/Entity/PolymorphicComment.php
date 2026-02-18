<?php

declare(strict_types=1);

namespace SpotTest\Entity;

use Spot\EntityInterface;
use Spot\MapperInterface;

/**
 * Polymorphic Comment
 *
 * @package Spot
 */
class PolymorphicComment extends \Spot\Entity
{
    protected static ?string $table = 'test_polymorphic_comments';

    public static function fields(): array
    {
        return [
            'id'            => ['type' => 'integer', 'primary' => true, 'autoincrement' => true],
            'item_type'     => ['type' => 'string', 'index' => 'item_type_id', 'required' => true, 'value' => 'post'],
            'item_id'       => ['type' => 'integer', 'index' => 'item_type_id', 'required' => true],
            'name'          => ['type' => 'string', 'required' => true],
            'email'         => ['type' => 'string', 'required' => true],
            'body'          => ['type' => 'text', 'required' => true],
            'date_created'  => ['type' => 'datetime'],
        ];
    }

    public static function relations(MapperInterface $mapper, EntityInterface $entity): array
    {
        return [
            'item' => $mapper->belongsTo($entity, 'SpotTest\Entity\\' . ucwords($entity->item_type), 'item_id'),
        ];
    }
}
