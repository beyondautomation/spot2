<?php

declare(strict_types=1);

namespace SpotTest\Entity;

use Spot\Entity;

/**
 * Child for CustomPkParent HasMany relation.
 */
class CustomPkChild extends Entity
{
    protected static ?string $table = 'test_custompk_children';

    #[\Override]
    public static function fields(): array
    {
        return [
            'id'        => ['type' => 'integer', 'autoincrement' => true, 'primary' => true],
            'parent_id' => ['type' => 'integer', 'required' => true, 'index' => true],
            'body'      => ['type' => 'string', 'length' => 128],
        ];
    }
}
