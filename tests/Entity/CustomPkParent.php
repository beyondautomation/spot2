<?php

declare(strict_types=1);

namespace SpotTest\Entity;

use Spot\Entity;
use Spot\EntityInterface;
use Spot\MapperInterface;

/**
 * Parent entity with a non-standard PK field name ('post_id' instead of 'id').
 * Tests that HasMany::save() uses primaryKey() rather than ->id.
 */
class CustomPkParent extends Entity
{
    protected static ?string $table = 'test_custompk_parents';

    #[\Override]
    public static function fields(): array
    {
        return [
            'post_id' => ['type' => 'integer', 'autoincrement' => true, 'primary' => true],
            'title'   => ['type' => 'string', 'length' => 128, 'required' => true],
        ];
    }

    #[\Override]
    public static function relations(MapperInterface $mapper, EntityInterface $entity): array
    {
        return [
            'children' => $mapper->hasMany($entity, CustomPkChild::class, 'parent_id'),
        ];
    }
}
