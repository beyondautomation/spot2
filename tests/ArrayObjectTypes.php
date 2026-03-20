<?php

declare(strict_types=1);

namespace SpotTest;

/**
 * @package Spot
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class ArrayObjectTypes extends \PHPUnit\Framework\TestCase
{
    public function testArray(): void
    {
        $entity = $this->getEntity();
        $this->assertFalse($entity->isModified('fld_array'));
        $entity->fld_array['value'] = 'modified';
        $this->assertTrue($entity->isModified('fld_array'));
    }

    public function testSimpleArray(): void
    {
        $entity = $this->getEntity();
        $this->assertFalse($entity->isModified('fld_simple_array'));
        $entity->fld_simple_array['value'] = 'modified';
        $this->assertTrue($entity->isModified('fld_simple_array'));
    }

    public function testJsonArray(): void
    {
        $entity = $this->getEntity();
        $this->assertFalse($entity->isModified('fld_json_array'));
        $entity->fld_json_array['value'] = 'modified';
        $this->assertTrue($entity->isModified('fld_json_array'));
    }

    public function testObject(): void
    {
        $entity = $this->getEntity();
        $this->assertFalse($entity->isModified('fld_object'));
        $entity->fld_object->value = 'modified';
        $this->assertTrue($entity->isModified('fld_object'));
    }

    /**
     * The basic entity for these tests
     */
    private function getEntity(): \SpotTest\Entity\ArrayObjectType
    {
        return new \SpotTest\Entity\ArrayObjectType([
            'fld_array' => ['value' => 'original'],
            'fld_simple_array' => ['value' => 'original'],
            'fld_json_array' => ['value' => 'original'],
            'fld_object' => (object) ['value' => 'original'],
        ]);
    }
}
