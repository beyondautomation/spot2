<?php

declare(strict_types=1);

namespace SpotTest\Entity;

use Spot\Entity;

/**
 * Entity with fields using PHP runtime 'value' expressions — tests that migrate()
 * does not emit invalid DEFAULT clauses (datetime for objects, integer for time()).
 */
class MigrateTimestamp extends Entity
{
    protected static ?string $table = 'test_migrate_timestamp';

    #[\Override]
    public static function fields(): array
    {
        return [
            'id'         => ['type' => 'integer', 'autoincrement' => true, 'primary' => true],
            'label'      => ['type' => 'string', 'length' => 64],
            'created_at' => ['type' => 'integer', 'value' => time()],  // integer mimics unix timestamp default
            'updated_at' => ['type' => 'datetime', 'value' => new \DateTime()],
        ];
    }
}
