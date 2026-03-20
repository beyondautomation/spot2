<?php

declare(strict_types=1);

namespace SpotTest;

/**
 * @package Spot
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
class Locator extends \PHPUnit\Framework\TestCase
{
    public function testGetConfig(): void
    {
        $cfg = new \Spot\Config();
        $spot = new \Spot\Locator($cfg);
        $this->assertInstanceOf(\Spot\Config::class, $spot->config());
    }

    public function testGetMapper(): void
    {
        $cfg = new \Spot\Config();
        $spot = new \Spot\Locator($cfg);
        $this->assertInstanceOf(\Spot\Mapper::class, $spot->mapper(\SpotTest\Entity\Post::class));
    }
}
