<?php

declare(strict_types=1);

namespace SpotTest\Entity;

use Spot\Entity;
use Spot\EntityInterface;
use Spot\MapperInterface;

/**
 * PostTag
 *
 * @package Spot
 */
class PostTag extends Entity
{
    protected static ?string $table = 'test_posttags';

    public static function fields(): array
    {
        return [
            'id'        => ['type' => 'integer', 'primary' => true, 'autoincrement' => true],
            'tag_id'    => ['type' => 'integer', 'required' => true, 'unique' => 'post_tag'],
            'post_id'   => ['type' => 'integer', 'required' => true, 'unique' => 'post_tag'],
            'random'    => ['type' => 'string'], // Totally unnecessary, but makes testing upserts easy
        ];
    }

    public static function relations(MapperInterface $mapper, EntityInterface $entity): array
    {
        return [
            'post' => $mapper->belongsTo($entity, 'SpotTest\Entity\Post', 'post_id'),
            'tag'  => $mapper->belongsTo($entity, 'SpotTest\Entity\Tag', 'tag_id'),
        ];
    }
}
