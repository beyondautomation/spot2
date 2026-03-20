<?php

declare(strict_types=1);

namespace SpotTest\Entity;

/**
 * CustomMethods
 *
 * @package Spot
 */
class CustomMethods extends \Spot\Entity
{
    protected static ?string $table = 'test_custom_methods';

    #[\Override]
    public static function fields(): array
    {
        return [
            'id' => ['type' => 'integer', 'primary' => true, 'autoincrement' => true],
            'test1' => ['type' => 'text'],
            'test2' => ['type' => 'text'],
            'test3' => ['type' => 'text'],
        ];
    }

    // Custom setter
    public function setTest1(string $value): string
    {
        return $value . '_test';
    }

    public function setTest2(string $value): string
    {
        $this->test3 = $value . '_copy';

        return $value;
    }

    // Custom getter
    public function getTest1(): string
    {
        return $this->get('test1') . '_gotten';
    }
}
