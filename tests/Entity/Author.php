<?php

declare(strict_types=1);

namespace SpotTest\Entity;

/**
 * Author
 *
 * @package Spot
 */
class Author extends \Spot\Entity
{
    protected static ?string $table = 'test_authors';

    public static function fields(): array
    {
        return [
            'id' => ['type' => 'integer', 'primary' => true, 'autoincrement' => true],
            'email' => ['type' => 'string', 'required' => true, 'unique' => true,
                'validation' => [
                    'email',
                    'length' => [4, 255],
                ],
            ], // Unique
            'password' => ['type' => 'text', 'required' => true],
            'is_admin' => ['type' => 'boolean', 'value' => false],
            'date_created' => ['type' => 'datetime', 'value' => new \DateTime()],
        ];
    }
}
