<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Bootstrap.php';

date_default_timezone_set('America/Chicago');

// PHPUnit <env> tags populate $_ENV. getenv() may not see them on all platforms.
$dbDsn = $_ENV['SPOT_DB_DSN'] ?? getenv('SPOT_DB_DSN') ?: 'sqlite::memory:';

$cfg = new \Spot\Config();
$cfg->addConnection('test', $dbDsn);

// Store in a static property so it survives across PHPUnit's test class boundary.
// A plain PHP global variable can become unreachable from static test methods
// depending on the PHP version and PHPUnit runner configuration.
\SpotTest\Bootstrap::$locator = new \Spot\Locator($cfg);

/**
 * Helper to get a mapper in tests.
 *
 * @param string $entityName Fully-qualified entity class name.
 *
 * @internal Use only in tests — not for production code.
 */
function test_spot_mapper(string $entityName): \Spot\Mapper
{
    return \SpotTest\Bootstrap::$locator->mapper($entityName);
}
