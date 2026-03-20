<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
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

    // Target PHP 8.3 minimum (matches composer.json require: >=8.3.0)
    ->withPhpSets(php83: true)

    ->withSets([
        // Core code quality — simplifies expressions, removes dead code, etc.
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,

        // Add missing type declarations across the board
        SetList::TYPE_DECLARATION,

        // Early return / guard clause patterns for cleaner nesting
        SetList::EARLY_RETURN,

        // Coding style normalisations safe to apply automatically
        SetList::CODING_STYLE,

        // PHPUnit annotations to attributes (safe — no constructor changes)
        PHPUnitSetList::ANNOTATIONS_TO_ATTRIBUTES,
    ])

    // Rules we explicitly skip because they conflict with Spot's design
    ->withSkip([
        // Rector sometimes wants to inline single-use variables that are assigned
        // by reference (&$var) — this breaks Spot's reference-based __get magic.
        \Rector\CodingStyle\Rector\Assign\SplitDoubleAssignRector::class,

        // Don't convert string interpolation — Spot uses it intentionally in SQL
        // generation and converting it reduces readability there.
        \Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector::class,
    ]);
