<?php

declare(strict_types=1);

namespace SpotTest\Entity;

use Spot\Entity;

/**
 * Entity with no serial/autoincrement
 *
 * @package Spot
 */
class NoSerial extends \Spot\Entity
{
    protected static ?string $table = 'test_noserial';

    public static function fields(): array
    {
        return [
            'id'    => ['type' => 'integer', 'primary' => true],
            'data'  => ['type' => 'string', 'required' => true],
        ];
    }
}
