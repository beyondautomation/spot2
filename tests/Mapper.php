<?php

declare(strict_types=1);

namespace SpotTest;

/**
 * @package Spot
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class Mapper extends \PHPUnit\Framework\TestCase
{
    public function testGetGenericMapper(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Author::class);
        $this->assertInstanceOf(\Spot\Mapper::class, $mapper);
    }

    public function testGetCustomEntityMapper(): void
    {
        $mapper = test_spot_mapper(\SpotTest\Entity\Event::class);
        $this->assertInstanceOf(Entity\Event::mapper(), $mapper);

        $query = $mapper->testQuery();
        $this->assertInstanceOf(\Spot\Query::class, $query);
    }
}
