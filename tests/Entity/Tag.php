<?php

declare(strict_types=1);

namespace SpotTest\Entity;

use Spot\EntityInterface;
use Spot\MapperInterface;

/**
 * Post
 *
 * @package Spot
 */
class Tag extends \Spot\Entity
{
    protected static ?string $table = 'test_tags';

    public static function fields(): array
    {
        return [
            'id'    => ['type' => 'integer', 'primary' => true, 'autoincrement' => true],
            'name'  => ['type' => 'string', 'required' => true],
        ];
    }

    public static function relations(MapperInterface $mapper, EntityInterface $entity): array
    {
        return [
            'posts' => $mapper->hasManyThrough($entity, 'SpotTest\Entity\Post', 'SpotTest\Entity\PostTag', 'tag_id', 'post_id'),
        ];
    }
}
