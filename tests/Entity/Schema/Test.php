<?php

declare(strict_types=1);

namespace SpotTest\Entity\Schema;

use Spot\Entity;

/**
 * Post
 *
 * @package Spot
 */
class Test extends Entity
{
    protected static ?string $table = 'spot_test.test_schema_test';

    public static function fields(): array
    {
        return [
            'id'           => ['type' => 'integer', 'autoincrement' => true, 'primary' => true],
            'unique'       => ['type' => 'integer', 'default' => 0, 'unique' => true],
            'index'        => ['type' => 'integer', 'default' => 0, 'index' => true],
        ];
    }
}
