<?php

declare(strict_types=1);

namespace SpotTest;

/**
 * Holds the shared Locator instance for the test suite.
 *
 * Using a static property avoids PHP's unreliable global variable scoping
 * across PHPUnit's multi-class bootstrap/test-runner boundary.
 */
class Bootstrap
{
    public static \Spot\Locator $locator;
}
