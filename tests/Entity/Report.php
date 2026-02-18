<?php

declare(strict_types=1);

namespace SpotTest\Entity;

use Spot\Entity;

/**
 * Post
 *
 * @package Spot
 */
class Report extends Entity
{
    protected static ?string $table = 'test_reports';

    public static function fields(): array
    {
        return [
            'id'           => ['type' => 'integer', 'autoincrement' => true, 'primary' => true],
            'date'         => ['type' => 'date', 'value' => new \DateTime(), 'required' => true, 'unique' => true],
            'result'       => ['type' => 'json', 'required' => true],
        ];
    }
}
