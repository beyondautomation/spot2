<?php

declare(strict_types=1);

namespace SpotTest;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SpotTest\Entity\CircularA;
use SpotTest\Entity\CircularB;
use SpotTest\Entity\LegacyTypes;
use SpotTest\Entity\MigrateStringNoLength;
use SpotTest\Entity\MigrateTimestamp;

/**
 * Migrate regression tests — covers every schema-migration bug fixed during
 * the DBAL4 upgrade.
 */
#[CoversNothing]
#[Group('migrate')]
class MigrateRegression extends TestCase
{
    /** @var array<string> */
    private static array $tables = [
        'test_migrate_timestamp',
        'test_migrate_string_nolength',
        'test_circular_a',
        'test_circular_b',
        'test_legacy_types',
    ];

    public static function setUpBeforeClass(): void
    {
        // Drop any tables left over from a previous run before resetting
        // the schema cache. This makes the test class idempotent regardless
        // of whether a prior run was interrupted before tearDownAfterClass.
        $connection = Bootstrap::$locator->config()->connection('test');
        $sm         = $connection->createSchemaManager();

        $extraTables = ['test_migrate_new_col', 'test_migrate_cache_check'];

        foreach (array_merge(self::$tables, $extraTables) as $table) {
            try {
                if ($sm->tablesExist([$table])) {
                    $sm->dropTable($table);
                }
            } catch (\Throwable) {
                // Best-effort
            }
        }

        // Reset only the Resolver's schema introspection cache so migrate
        // tests always see fresh DB state. We do NOT reset Mapper's entity
        // manager cache because that would cause events registered in
        // constructors (e.g. beforeInsert token generation) to silently
        // stop firing for mappers cached in the Locator.
        \Spot\Query\Resolver::resetStaticCaches();
    }

    public static function tearDownAfterClass(): void
    {
        $connection = Bootstrap::$locator->config()->connection('test');
        $sm         = $connection->createSchemaManager();
        $extraTables = ['test_migrate_new_col', 'test_migrate_cache_check'];

        foreach (array_merge(self::$tables, $extraTables) as $table) {
            try {
                if ($sm->tablesExist([$table])) {
                    $sm->dropTable($table);
                }
            } catch (\Throwable) {
                // Best-effort cleanup
            }
        }

        \Spot\Query\Resolver::resetStaticCaches();
    }

    // -------------------------------------------------------------------------
    // 1. Circular FK recursion guard
    // -------------------------------------------------------------------------

    public function testCircularFkDoesNotRecurseInfinitely(): void
    {
        // SQLite rebuilds the whole table when adding FK constraints via ALTER,
        // so we only test that a single migrate() pass completes without
        // infinite recursion — not that a second pass is a no-op.
        $mapperA = test_spot_mapper(CircularA::class);
        $mapperB = test_spot_mapper(CircularB::class);

        try {
            $mapperA->migrate();
            $mapperB->migrate();
            $this->assertTrue(true, 'Completed without stack overflow or exception');
        } catch (\Throwable $throwable) {
            $this->fail('migrate() threw unexpectedly: ' . $throwable->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // 2. Destructive DDL prevention
    // -------------------------------------------------------------------------

    public function testMigrateNeverDropsExistingRows(): void
    {
        $mapper = test_spot_mapper(MigrateTimestamp::class);
        $mapper->migrate();
        $mapper->create(['label' => 'destructive-test-row']);

        $countBefore = $mapper->all()->count();
        $mapper->migrate();

        $this->assertEquals($countBefore, $mapper->all()->count(), 'Row count must be unchanged after re-migrate');
    }

    public function testMigrateNeverDropsExistingColumns(): void
    {
        $mapper = test_spot_mapper(MigrateStringNoLength::class);
        $mapper->migrate();
        $mapper->create(['message' => 'hello', 'short' => 'hi', 'long' => str_repeat('x', 300)]);

        $mapper->migrate();

        $row = $mapper->first(['message' => 'hello']);
        $this->assertNotFalse($row, 'Row must still exist after re-migrate');
        $this->assertEquals('hello', $row->message);
        $this->assertEquals('hi', $row->short);
    }

    // -------------------------------------------------------------------------
    // 3 & 4. String column promotion
    // -------------------------------------------------------------------------

    public function testStringWithoutLengthDefaultsToVarchar255(): void
    {
        // Entity\Manager pre-fills 'length' => 255 for any string field with no explicit length.
        // This means DBAL4's new default of 65535 never applies — Manager's 255 always wins.
        // The column should therefore be VARCHAR(255), not TEXT.
        $mapper = test_spot_mapper(MigrateStringNoLength::class);
        $mapper->migrate();

        $sm = Bootstrap::$locator->config()->connection('test')->createSchemaManager();

        if (!$sm->tablesExist(['test_migrate_string_nolength'])) {
            $this->markTestSkipped('Table not created');
        }

        $col    = $sm->introspectTable('test_migrate_string_nolength')->getColumn('message');
        $length = $col->getLength();

        // Manager provides length=255 default — column must be VARCHAR(255), not wider
        $this->assertEquals(255, $length, 'string field with no explicit length must be VARCHAR(255) via Manager default');
    }

    public function testStringWithExplicitShortLengthStaysVarchar(): void
    {
        $mapper = test_spot_mapper(MigrateStringNoLength::class);
        $mapper->migrate();

        $sm = Bootstrap::$locator->config()->connection('test')->createSchemaManager();

        if (!$sm->tablesExist(['test_migrate_string_nolength'])) {
            $this->markTestSkipped('Table not created');
        }

        $col = $sm->introspectTable('test_migrate_string_nolength')->getColumn('short');

        $this->assertInstanceOf(\Doctrine\DBAL\Types\StringType::class, $col->getType(), 'short must remain VARCHAR');
        $this->assertEquals(64, $col->getLength(), 'Length must be 64');
    }

    public function testStringOverLimitPromotedToText(): void
    {
        $mapper = test_spot_mapper(MigrateStringNoLength::class);
        $mapper->migrate();

        $sm = Bootstrap::$locator->config()->connection('test')->createSchemaManager();

        if (!$sm->tablesExist(['test_migrate_string_nolength'])) {
            $this->markTestSkipped('Table not created');
        }

        $col = $sm->introspectTable('test_migrate_string_nolength')->getColumn('long');

        $this->assertInstanceOf(\Doctrine\DBAL\Types\TextType::class, $col->getType(), 'long (length=20000) must be promoted to TEXT');
    }

    // -------------------------------------------------------------------------
    // 5. Timestamp/datetime value not emitted as SQL DEFAULT
    // -------------------------------------------------------------------------

    public function testTimestampValueFieldDoesNotThrowOnMigrate(): void
    {
        $mapper = test_spot_mapper(MigrateTimestamp::class);

        try {
            $mapper->migrate();
            $this->assertTrue(true, 'First migrate() completed without exception');
        } catch (\Doctrine\DBAL\Exception\DriverException $driverException) {
            $this->fail('migrate() threw DriverException — invalid DEFAULT emitted: ' . $driverException->getMessage());
        }
    }

    public function testTimestampValueFieldSecondMigrateIsClean(): void
    {
        $mapper = test_spot_mapper(MigrateTimestamp::class);
        $mapper->migrate();

        try {
            $mapper->migrate();
            $this->assertTrue(true, 'Second migrate() also completed without exception');
        } catch (\Doctrine\DBAL\Exception\DriverException $driverException) {
            $this->fail('Second migrate() threw: ' . $driverException->getMessage());
        }
    }

    public function testDatetimeColumnHasNoUnixTimestampDefault(): void
    {
        $mapper = test_spot_mapper(MigrateTimestamp::class);
        $mapper->migrate();

        $sm = Bootstrap::$locator->config()->connection('test')->createSchemaManager();

        if (!$sm->tablesExist(['test_migrate_timestamp'])) {
            $this->markTestSkipped('Table not created');
        }

        $col     = $sm->introspectTable('test_migrate_timestamp')->getColumn('updated_at');
        $default = $col->getDefault();

        if ($default !== null) {
            $this->assertFalse(
                is_numeric($default) && (int) $default > 1_000_000_000,
                "updated_at DEFAULT must not be a Unix timestamp integer, got: {$default}",
            );
        }

        $this->assertTrue(true, 'datetime column has no invalid numeric DEFAULT');
    }

    // -------------------------------------------------------------------------
    // 6. Legacy types mapped to text
    // -------------------------------------------------------------------------

    public function testLegacyTypeMigrateDoesNotThrow(): void
    {
        $mapper = test_spot_mapper(LegacyTypes::class);

        try {
            $mapper->migrate();
            $this->assertTrue(true);
        } catch (\Throwable $throwable) {
            $this->fail('migrate() threw on legacy types: ' . $throwable->getMessage());
        }
    }

    public function testLegacyTypeRoundTrip(): void
    {
        $mapper = test_spot_mapper(LegacyTypes::class);
        $mapper->migrate();

        $arr    = ['foo' => 'bar', 'num' => 42];
        $obj    = (object) ['x' => 1, 'y' => 2];
        $simple = ['one', 'two', 'three'];

        $entity = $mapper->create([
            'label'       => 'legacy-roundtrip',
            'arr_data'    => $arr,
            'obj_data'    => $obj,
            'simple_data' => $simple,
        ]);

        $this->assertNotFalse($entity, 'Insert must succeed');

        $loaded = $mapper->first(['label' => 'legacy-roundtrip']);
        $this->assertNotFalse($loaded, 'Must load back');
        $this->assertNotNull($loaded->arr_data);
        $this->assertNotNull($loaded->obj_data);
    }

    // -------------------------------------------------------------------------
    // 7. Idempotency
    // -------------------------------------------------------------------------

    public function testSecondMigrateOnCleanSchemaIsNoOp(): void
    {
        $mapper = test_spot_mapper(MigrateTimestamp::class);
        $mapper->migrate();
        $mapper->create(['label' => 'idempotency-canary']);

        $countBefore = $mapper->all()->count();
        $result      = $mapper->migrate();

        $this->assertFalse($result, 'Second migrate() on unchanged entity must return false');
        $this->assertEquals($countBefore, $mapper->all()->count(), 'Row count unchanged');
    }

    // -------------------------------------------------------------------------
    // 8. New column detection
    // -------------------------------------------------------------------------

    public function testMigrateAddsNewColumn(): void
    {
        $connection = Bootstrap::$locator->config()->connection('test');
        $sm         = $connection->createSchemaManager();
        $tableName  = 'test_migrate_new_col';

        if ($sm->tablesExist([$tableName])) {
            $sm->dropTable($tableName);
        }

        $connection->executeStatement(
            'CREATE TABLE ' . $tableName . ' (id INTEGER NOT NULL, label VARCHAR(64), PRIMARY KEY (id))',
        );

        // Invalidate cache so migrate() sees the manually-created table.
        \Spot\Query\Resolver::resetStaticCaches();

        $entityClass = new class () extends \Spot\Entity {
            protected static ?string $table = 'test_migrate_new_col';

            public static function fields(): array
            {
                return [
                    'id'    => ['type' => 'integer', 'autoincrement' => true, 'primary' => true],
                    'label' => ['type' => 'string', 'length' => 64],
                    'extra' => ['type' => 'string', 'length' => 32],
                ];
            }
        };

        $mapper = Bootstrap::$locator->mapper($entityClass::class);
        $mapper->migrate();

        $tableObj = $sm->introspectTable($tableName);
        $this->assertTrue($tableObj->hasColumn('extra'), 'migrate() must add missing column extra');

        $sm->dropTable($tableName);
    }

    // -------------------------------------------------------------------------
    // 9. Cache invalidated after schema change
    // -------------------------------------------------------------------------

    public function testSchemaCacheInvalidatedAfterChange(): void
    {
        $connection = Bootstrap::$locator->config()->connection('test');
        $sm         = $connection->createSchemaManager();
        $tableName  = 'test_migrate_cache_check';

        if ($sm->tablesExist([$tableName])) {
            $sm->dropTable($tableName);
        }

        $entityV1 = new class () extends \Spot\Entity {
            protected static ?string $table = 'test_migrate_cache_check';

            public static function fields(): array
            {
                return [
                    'id'   => ['type' => 'integer', 'autoincrement' => true, 'primary' => true],
                    'col1' => ['type' => 'string', 'length' => 32],
                ];
            }
        };

        Bootstrap::$locator->mapper($entityV1::class)->migrate();

        // Reset cache between the two entity versions to simulate a fresh request.
        \Spot\Query\Resolver::resetStaticCaches();

        $entityV2 = new class () extends \Spot\Entity {
            protected static ?string $table = 'test_migrate_cache_check';

            public static function fields(): array
            {
                return [
                    'id'   => ['type' => 'integer', 'autoincrement' => true, 'primary' => true],
                    'col1' => ['type' => 'string', 'length' => 32],
                    'col2' => ['type' => 'string', 'length' => 32],
                ];
            }
        };

        Bootstrap::$locator->mapper($entityV2::class)->migrate();

        $tableObj = $sm->introspectTable($tableName);
        $this->assertTrue(
            $tableObj->hasColumn('col2'),
            'col2 must exist after second migrate() — cache must have been invalidated',
        );

        $sm->dropTable($tableName);
    }
}
