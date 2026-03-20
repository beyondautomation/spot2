#!/usr/bin/env php
<?php

/**
 * Pre-production Schema Audit Script
 * ====================================
 * Connects to a copy of your production database, runs every mapper's migrate()
 * in DRY-RUN mode (no SQL executed), and reports what would change.
 *
 * Usage:
 *   php audit_schema.php --dsn="mysql://user:pass@host/dbname" --bootstrap="/path/to/app/bootstrap.php"
 *   php audit_schema.php --dsn="mysql://user:pass@host/dbname" --mappers="/path/to/mappers/dir"
 *
 * Options:
 *   --dsn         DBAL-style DSN for the database copy to audit  [required]
 *   --bootstrap   Path to your application bootstrap file that registers all mappers/entities
 *   --mappers     Path to scan for *Mapper.php files (alternative to --bootstrap)
 *   --namespace   PHP namespace prefix to strip when resolving mapper class names (default: App\Model\Mapper)
 *   --entity-ns   PHP namespace prefix for entity classes (default: App\Model)
 *   --no-color    Disable ANSI colors
 *   --help        Show this help
 *
 * Exit codes:
 *   0  All schemas are up-to-date — safe to deploy
 *   1  Schema changes detected — review before deploying
 *   2  Errors encountered — do not deploy
 */

declare(strict_types=1);

// ─── Helpers ─────────────────────────────────────────────────────────────────

function usage(): void
{
    echo <<<USAGE
Pre-production Schema Audit
Usage: php audit_schema.php [options]

Required:
  --dsn=DSN           DBAL DSN for the DB copy, e.g. mysql://user:pass@host/dbname

Source of entities (choose one):
  --bootstrap=FILE    App bootstrap file that registers entities/mappers
  --mappers=DIR       Directory to scan for *Mapper.php files

Optional:
  --namespace=NS      Mapper class namespace prefix  (default: App\Model\Mapper)
  --entity-ns=NS      Entity class namespace prefix  (default: App\Model)
  --no-color          Disable ANSI output
  --help              Show this help

USAGE;
}

function colored(string $text, string $color, bool $enabled): string
{
    if (!$enabled) {
        return $text;
    }
    $codes = [
        'red'    => "\033[31m",
        'green'  => "\033[32m",
        'yellow' => "\033[33m",
        'cyan'   => "\033[36m",
        'bold'   => "\033[1m",
        'reset'  => "\033[0m",
    ];
    return ($codes[$color] ?? '') . $text . $codes['reset'];
}

// ─── Argument parsing ────────────────────────────────────────────────────────

$opts = getopt('', [
    'dsn:',
    'bootstrap:',
    'mappers:',
    'namespace::',
    'entity-ns::',
    'no-color',
    'help',
]);

if (isset($opts['help'])) {
    usage();
    exit(0);
}

$useColor    = !isset($opts['no-color']) && stream_isatty(STDOUT);
$dsn         = $opts['dsn']        ?? null;
$bootstrapFile = $opts['bootstrap'] ?? null;
$mappersDir  = $opts['mappers']    ?? null;
$mapperNs    = rtrim($opts['namespace']  ?? 'App\\Model\\Mapper', '\\');
$entityNs    = rtrim($opts['entity-ns'] ?? 'App\\Model', '\\');

if (!$dsn) {
    echo colored("ERROR: --dsn is required.\n", 'red', $useColor);
    usage();
    exit(2);
}

if (!$bootstrapFile && !$mappersDir) {
    echo colored("ERROR: Provide either --bootstrap or --mappers.\n", 'red', $useColor);
    usage();
    exit(2);
}

// ─── Autoloader / Bootstrap ──────────────────────────────────────────────────

// Try to find composer autoloader relative to script or CWD
$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    getcwd() . '/vendor/autoload.php',
];

$autoloaded = false;
foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        require_once $candidate;
        $autoloaded = true;
        break;
    }
}

if (!$autoloaded) {
    echo colored("ERROR: Could not find vendor/autoload.php. Run from the project root or adjust the path.\n", 'red', $useColor);
    exit(2);
}

if ($bootstrapFile) {
    if (!file_exists($bootstrapFile)) {
        echo colored("ERROR: Bootstrap file not found: $bootstrapFile\n", 'red', $useColor);
        exit(2);
    }
    require_once $bootstrapFile;
}

// ─── Collect mapper classes ──────────────────────────────────────────────────

$mapperClasses = [];

if ($mappersDir) {
    if (!is_dir($mappersDir)) {
        echo colored("ERROR: Mappers directory not found: $mappersDir\n", 'red', $useColor);
        exit(2);
    }

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mappersDir));
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $basename = $file->getBasename('.php');
        if (!str_ends_with($basename, 'Mapper')) {
            continue;
        }

        // Derive class name from namespace + filename
        $relativePath = ltrim(str_replace(realpath($mappersDir), '', realpath($file->getPathname())), DIRECTORY_SEPARATOR);
        $classPath     = str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relativePath);
        $className     = $mapperNs . '\\' . $classPath;

        if (!class_exists($className)) {
            // Try including the file and re-checking
            require_once $file->getPathname();
        }

        if (class_exists($className) && is_subclass_of($className, \Spot\Mapper::class)) {
            $mapperClasses[] = $className;
        }
    }
} else {
    // Scan all loaded classes for Spot\Mapper subclasses
    foreach (get_declared_classes() as $class) {
        if (is_subclass_of($class, \Spot\Mapper::class)) {
            $mapperClasses[] = $class;
        }
    }
}

if (count($mapperClasses) === 0) {
    echo colored("WARNING: No mapper classes found. Check --bootstrap or --mappers.\n", 'yellow', $useColor);
    exit(2);
}

sort($mapperClasses);

// ─── Set up DBAL connection ──────────────────────────────────────────────────

try {
    $config     = new \Doctrine\DBAL\Configuration();
    $connection = \Doctrine\DBAL\DriverManager::getConnection(
        ['url' => $dsn],
        $config
    );
    $connection->connect();
} catch (\Throwable $e) {
    echo colored("ERROR: Cannot connect to database: " . $e->getMessage() . "\n", 'red', $useColor);
    exit(2);
}

// ─── Audit ──────────────────────────────────────────────────────────────────

echo colored("\nSpot2 Pre-Production Schema Audit\n", 'bold', $useColor);
echo colored("Database: ", 'cyan', $useColor) . $dsn . "\n";
echo colored("Mappers:  ", 'cyan', $useColor) . count($mapperClasses) . " found\n\n";
echo str_repeat('─', 70) . "\n\n";

$summary = [
    'up_to_date'  => [],
    'needs_alter' => [],
    'needs_create'=> [],
    'errors'      => [],
    'destructive' => [],
];

$schemaManager = $connection->createSchemaManager();

// Build Spot locator pointing at the audit DB
$spotConfig = new \Spot\Config();
$spotConfig->addConnection('audit', $connection);
$locator = new \Spot\Locator($spotConfig);

foreach ($mapperClasses as $mapperClass) {
    try {
        /** @var \Spot\Mapper $mapper */
        $mapper    = $locator->mapper($mapperClass);
        $entity    = $mapper->entity();
        $table     = $entity::table();
        $resolver  = $mapper->resolver();

        $tableExists = $schemaManager->tablesExist([$table]);

        if (!$tableExists) {
            // Table does not exist — migrate would CREATE it
            $newSchema = $resolver->migrateCreateSchema();
            $sqls      = $newSchema->toSql($connection->getDatabasePlatform());

            echo colored("  [CREATE] ", 'yellow', $useColor) . "$mapperClass\n";
            echo colored("           Table '$table' does not exist — would be created.\n", 'yellow', $useColor);
            foreach ($sqls as $sql) {
                echo "           " . colored($sql, 'yellow', $useColor) . "\n";
            }
            echo "\n";

            $summary['needs_create'][] = $mapperClass;
            continue;
        }

        // Table exists — check for diffs
        $currentSchema = $schemaManager->introspectSchema();
        $newSchema     = $resolver->migrateCreateSchema();
        $comparator    = $schemaManager->createComparator();
        $schemaDiff    = $comparator->compareSchemas($currentSchema, $newSchema);
        $allSqls       = $connection->getDatabasePlatform()->getAlterSchemaSQL($schemaDiff);

        if (count($allSqls) === 0) {
            echo colored("  [OK]     ", 'green', $useColor) . "$mapperClass ($table)\n";
            $summary['up_to_date'][] = $mapperClass;
            continue;
        }

        // Separate safe vs destructive
        $safeSqls       = [];
        $destructiveSqls = [];

        foreach ($allSqls as $sql) {
            if (preg_match('/^\s*DROP\s+/i', $sql)) {
                $destructiveSqls[] = $sql;
                continue;
            }
            if (preg_match('/^\s*ALTER\s+TABLE\s+/i', $sql) && preg_match('/\bDROP\b/i', $sql)) {
                $destructiveSqls[] = $sql;
                // Also try to extract the safe part
                preg_match('/^\s*(ALTER\s+TABLE\s+\S+)\s+/i', $sql, $m);
                if (isset($m[1])) {
                    $rest    = substr($sql, strlen($m[0]));
                    $clauses = preg_split('/,\s*(?=[A-Z])/i', $rest) ?: [$rest];
                    $safe    = array_filter($clauses, static fn($c) => !preg_match('/^\s*DROP\b/i', trim($c)));
                    if ($safe) {
                        $safeSqls[] = $m[1] . ' ' . implode(', ', $safe);
                    }
                }
                continue;
            }
            $safeSqls[] = $sql;
        }

        if ($destructiveSqls) {
            echo colored("  [DANGER] ", 'red', $useColor) . "$mapperClass ($table)\n";
            echo colored("           Destructive SQL detected (would be SUPPRESSED by migrate()):\n", 'red', $useColor);
            foreach ($destructiveSqls as $sql) {
                echo "           " . colored($sql, 'red', $useColor) . "\n";
            }
            $summary['destructive'][] = $mapperClass;
        }

        if ($safeSqls) {
            echo colored("  [ALTER]  ", 'yellow', $useColor) . "$mapperClass ($table)\n";
            foreach ($safeSqls as $sql) {
                echo "           " . colored($sql, 'yellow', $useColor) . "\n";
            }
            $summary['needs_alter'][] = $mapperClass;
        }

        echo "\n";
    } catch (\Throwable $e) {
        echo colored("  [ERROR]  ", 'red', $useColor) . "$mapperClass\n";
        echo colored("           " . $e->getMessage() . "\n", 'red', $useColor);
        echo "\n";
        $summary['errors'][] = $mapperClass . ': ' . $e->getMessage();
    }
}

// ─── Summary ────────────────────────────────────────────────────────────────

echo "\n" . str_repeat('─', 70) . "\n\n";
echo colored("Summary\n", 'bold', $useColor);

$upToDate   = count($summary['up_to_date']);
$alters     = count($summary['needs_alter']);
$creates    = count($summary['needs_create']);
$destructive= count($summary['destructive']);
$errors     = count($summary['errors']);

echo colored("  ✓  Up-to-date:           ", 'green', $useColor) . "$upToDate\n";
echo colored("  ⚠  Need ALTER:           ", 'yellow', $useColor) . "$alters\n";
echo colored("  ⚠  Need CREATE:          ", 'yellow', $useColor) . "$creates\n";
echo colored("  ✗  Destructive (blocked):", 'red', $useColor)    . " $destructive\n";
echo colored("  ✗  Errors:               ", 'red', $useColor)    . " $errors\n";

if ($errors > 0) {
    echo "\n" . colored("Errors (do not deploy until resolved):\n", 'red', $useColor);
    foreach ($summary['errors'] as $err) {
        echo "  - $err\n";
    }
}

if ($destructive > 0) {
    echo "\n" . colored("WARNING: Destructive DDL was detected for the above mappers.\n", 'red', $useColor);
    echo colored("         migrate() will SUPPRESS these statements, so your data is safe.\n", 'red', $useColor);
    echo colored("         However, this likely means the DB has extra columns/tables that\n", 'red', $useColor);
    echo colored("         are not in the entity definition. Investigate before deploying.\n", 'red', $useColor);
}

echo "\n";

// ─── Exit code ──────────────────────────────────────────────────────────────

if ($errors > 0) {
    echo colored("❌  ERRORS found — do not deploy.\n\n", 'red', $useColor);
    exit(2);
}

if ($alters > 0 || $creates > 0) {
    echo colored("⚠   Schema changes will be applied on first request after deploy.\n", 'yellow', $useColor);
    echo colored("    Review the ALTER/CREATE statements above before proceeding.\n\n", 'yellow', $useColor);
    exit(1);
}

echo colored("✅  All schemas are up-to-date. Safe to deploy.\n\n", 'green', $useColor);
exit(0);
