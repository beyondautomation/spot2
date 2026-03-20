<?php

declare(strict_types=1);

namespace SpotTest\Entity;

use Spot\Entity;

/**
 * Category — the "belongs-to" target for Article.
 */
class Category extends Entity
{
    protected static ?string $table = 'test_categories';

    #[\Override]
    public static function fields(): array
    {
        return [
            'id'   => ['type' => 'integer', 'autoincrement' => true, 'primary' => true],
            'name' => ['type' => 'string', 'length' => 64, 'required' => true],
        ];
    }
}
