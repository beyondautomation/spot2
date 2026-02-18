<?php

declare(strict_types=1);

/**
 * PHP CS Fixer configuration for Spot2.
 *
 * Run checks:  composer cs:check
 * Auto-fix:    composer cs:fix
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->notPath('vendor');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // ── Presets ──────────────────────────────────────────────────────────
        '@PSR12'                     => true,
        '@PHP81Migration'            => true,
        '@PHP82Migration'            => true,
        '@PHP83Migration'            => true,

        // ── Imports ──────────────────────────────────────────────────────────
        'ordered_imports'            => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'          => true,
        'global_namespace_import'    => [
            'import_classes'    => false,
            'import_constants'  => false,
            'import_functions'  => false,
        ],

        // ── Arrays ───────────────────────────────────────────────────────────
        'array_syntax'               => ['syntax' => 'short'],
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'no_whitespace_before_comma_in_array' => true,
        'whitespace_after_comma_in_array'     => true,

        // ── Strings ──────────────────────────────────────────────────────────
        'single_quote'               => true,
        'no_trailing_whitespace'     => true,

        // ── Functions ────────────────────────────────────────────────────────
        'function_typehint_space'    => true,
        'return_type_declaration'    => ['space_before' => 'none'],
        'no_useless_return'          => true,
        'nullable_type_declaration_for_default_null_value' => true,

        // ── Classes ──────────────────────────────────────────────────────────
        'class_attributes_separation' => [
            'elements' => [
                'const'    => 'one',
                'property' => 'one',
                'method'   => 'one',
            ],
        ],
        'ordered_class_elements'     => [
            'order' => [
                'use_trait',
                'case',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public_static',
                'property_protected_static',
                'property_private_static',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'magic',
                'phpunit',
                'method_public_static',
                'method_protected_static',
                'method_private_static',
                'method_public',
                'method_protected',
                'method_private',
            ],
        ],
        'visibility_required'        => true,

        // ── Comments & DocBlocks ─────────────────────────────────────────────
        'phpdoc_align'               => ['align' => 'vertical'],
        'phpdoc_no_empty_return'     => true,
        'phpdoc_scalar'              => true,
        'phpdoc_separation'          => true,
        'phpdoc_trim'                => true,
        'phpdoc_types'               => true,
        'phpdoc_var_without_name'    => true,
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed'    => true,
            'remove_inheritdoc' => false,
        ],

        // ── Modern PHP ───────────────────────────────────────────────────────
        'modernize_types_casting'    => true,
        'no_alias_functions'         => true,
        'is_null'                    => true,
        'use_arrow_functions'        => false,  // keep explicit closures for readability
        'declare_strict_types'       => true,

        // ── Miscellaneous ────────────────────────────────────────────────────
        'no_unused_imports'          => true,
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw', 'try', 'foreach', 'if'],
        ],
        'cast_spaces'                => ['space' => 'single'],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
