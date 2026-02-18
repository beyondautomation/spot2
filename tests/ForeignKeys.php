<?php

declare(strict_types=1);

namespace SpotTest;

/**
 * @package Spot
 */
class ForeignKeys extends \PHPUnit\Framework\TestCase
{
    private static $entities = ['Author', 'Post', 'RecursiveEntity'];

    public static function setUpBeforeClass(): void
    {
        foreach (self::$entities as $entity) {
            test_spot_mapper('\SpotTest\Entity\\' . $entity)->migrate();
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$entities as $entity) {
            test_spot_mapper('\SpotTest\Entity\\' . $entity)->dropTable();
        }
    }

    public function testForeignKeyMigration()
    {
        $mapper = test_spot_mapper('\SpotTest\Entity\Post');
        $entity = $mapper->entity();
        $table = $entity::table();
        $schemaManager = $mapper->connection()->createSchemaManager();
        $foreignKeys = $schemaManager->listTableForeignKeys($table);

        $this->assertEquals(1, count($foreignKeys));
    }
}
