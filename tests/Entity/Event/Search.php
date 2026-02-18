<?php

declare(strict_types=1);

namespace SpotTest\Entity\Event;

use Spot\Entity;
use Spot\EntityInterface;
use Spot\MapperInterface;

/**
 * Event Search Index
 *
 * @package Spot
 */
class Search extends Entity
{
    protected static ?string $table = 'test_events_search';

    // MyISAM table for FULLTEXT searching
    protected static array $tableOptions = [
        'engine' => 'MyISAM',
    ];

    public static function fields(): array
    {
        return [
            'id'        => ['type' => 'integer', 'primary' => true, 'autoincrement' => true],
            'event_id'  => ['type' => 'integer', 'index' => true, 'required' => true],
            'body'      => ['type' => 'text', 'required' => true, 'fulltext' => true],
        ];
    }

    public static function relations(MapperInterface $mapper, EntityInterface $entity): array
    {
        return [
            'event' => $mapper->belongsTo($entity, 'SpotTest\Entity\Event', 'event_id'),
        ];
    }
}
