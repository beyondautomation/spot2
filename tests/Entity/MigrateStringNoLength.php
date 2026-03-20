<?php

declare(strict_types=1);

namespace SpotTest\Entity;

use Spot\Entity;

/**
 * Entity with a 'string' field that has no explicit length — tests that
 * migrate() promotes it to TEXT rather than VARCHAR(65535).
 */
class MigrateStringNoLength extends Entity
{
    protected static ?string $table = 'test_migrate_string_nolength';

    #[\Override]
    public static function fields(): array
    {
        return [
            'id'      => ['type' => 'integer', 'autoincrement' => true, 'primary' => true],
            'message' => ['type' => 'string'],                          // no explicit length — Manager fills 255, stays VARCHAR(255)
            'short'   => ['type' => 'string', 'length' => 64],         // explicit short length — stays VARCHAR
            'long'    => ['type' => 'string', 'length' => 20000],      // over limit — must become TEXT
        ];
    }
}
