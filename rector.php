<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\PHPUnit\Set\PHPUnitSetList;

/**
 * Rector configuration for Spot2.
 *
 * Preview changes: composer rector:dry
 * Apply changes:   composer rector:fix
 *
 * Docs: https://getrector.org
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])

    // Target PHP 8.3 (matches composer.json require)
    ->withPhpSets(php83: true)

    ->withSets([
        // Code-quality improvements that are safe across versions
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,

        // PHPUnit 10/11 compatibility
        PHPUnitSetList::PHPUNIT_100,
    ])

    // Rules we explicitly skip because they would break Spot's design
    ->withSkip([
        // Entity::fields() and ::relations() are static on user-defined subclasses —
        // turning them into non-static would break every existing entity definition.
        \Rector\Php71\Rector\FuncCall\CountOnNullRector::class,
        // Spot's collection magic __get is intentional; typed properties would require
        // all subclass fields to be declared, breaking the dynamic data[] pattern.
        \Rector\Php74\Rector\Property\TypedPropertyRector::class => [
            __DIR__ . '/src/Entity.php',
            __DIR__ . '/src/Entity/Manager.php',
            __DIR__ . '/src/Config.php',
        ],
    ]);
