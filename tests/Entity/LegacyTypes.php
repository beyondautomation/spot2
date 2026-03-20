<?php

declare(strict_types=1);

namespace SpotTest\Entity;

use Spot\Entity;

/**
 * Entity with legacy DBAL types (array, object, simple_array) that were removed
 * in DBAL4. Tests that migrate() maps them to 'text' without errors, and that
 * CRUD round-trips serialize/unserialize correctly.
 */
class LegacyTypes extends Entity
{
    protected static ?string $table = 'test_legacy_types';

    #[\Override]
    public static function fields(): array
    {
        return [
            'id'           => ['type' => 'integer', 'autoincrement' => true, 'primary' => true],
            'arr_data'     => ['type' => 'array'],
            'obj_data'     => ['type' => 'object'],
            'simple_data'  => ['type' => 'simple_array'],
            'label'        => ['type' => 'string', 'length' => 64],
        ];
    }
}
