<?php

declare(strict_types=1);

namespace SpotTest\Entity;

use Spot\Entity;
use Spot\EntityInterface;
use Spot\MapperInterface;

/**
 * Article with an optional nullable BelongsTo relation (category_id can be NULL).
 * Used to test that eager-loading a collection where some rows have NULL FK
 * does not bleed the resolved relation from one entity into another.
 */
class Article extends Entity
{
    protected static ?string $table = 'test_articles';

    #[\Override]
    public static function fields(): array
    {
        return [
            'id'          => ['type' => 'integer', 'autoincrement' => true, 'primary' => true],
            'title'       => ['type' => 'string', 'length' => 128, 'required' => true],
            'category_id' => ['type' => 'integer', 'index' => true],   // nullable — no 'required'
        ];
    }

    #[\Override]
    public static function relations(MapperInterface $mapper, EntityInterface $entity): array
    {
        return [
            'category' => $mapper->belongsTo($entity, Category::class, 'id', 'category_id'),
        ];
    }
}
