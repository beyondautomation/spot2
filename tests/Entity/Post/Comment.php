<?php

declare(strict_types=1);

namespace SpotTest\Entity\Post;

use Spot\Entity;
use Spot\EntityInterface;
use Spot\MapperInterface;
use Spot\Query;

/**
 * Post Comment
 *
 * @package Spot
 */
class Comment extends Entity
{
    protected static ?string $table = 'test_post_comments';

    public static function fields(): array
    {
        return [
            'id'            => ['type' => 'integer', 'primary' => true, 'autoincrement' => true],
            'post_id'       => ['type' => 'integer', 'index' => true, 'required' => true],
            'name'          => ['type' => 'string', 'required' => true],
            'email'         => ['type' => 'string', 'required' => true],
            'body'          => ['type' => 'text', 'required' => true],
            'date_created'  => ['type' => 'datetime'],
        ];
    }

    public static function scopes(): array
    {
        return [
            'yesterday' => function (Query $query) {
                return $query->where(['date_created :gt' => new \DateTime('yesterday'), 'date_created :lt' => new \DateTime('today')]);
            },
        ];
    }

    public static function relations(MapperInterface $mapper, EntityInterface $entity): array
    {
        return [
            'post' => $mapper->belongsTo($entity, 'SpotTest\Entity\Post', 'post_id'),
        ];
    }
}
