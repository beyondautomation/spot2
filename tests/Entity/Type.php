<?php

declare(strict_types=1);

namespace SpotTest\Entity;

/**
 * Types
 * Exists solely for the purpose of testing custom types
 *
 * @package Spot
 */
class Type extends \Spot\Entity
{
    // Declared 'public static' here so they can be modified by tests - this is for TESTING ONLY
    public static $_fields = [
        'id'            => ['type' => 'integer', 'primary' => true, 'serial' => true],
        'serialized'    => ['type' => 'json'],
        'date_created'  => ['type' => 'datetime'],
    ];

    protected static $_datasource = 'test_types';

    public static function fields(): array
    {
        return self::$_fields;
    }
}
