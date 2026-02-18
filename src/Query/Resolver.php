<?php

declare(strict_types=1);

namespace Spot\Query;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Spot\Entity\Collection;
use Spot\Exception;
use Spot\Mapper;
use Spot\Query;
use Spot\Relation\BelongsTo;

/**
 * Query Resolver — translates Spot queries into DBAL operations.
 *
 * Handles schema migration, SELECT, INSERT, UPDATE, DELETE, TRUNCATE, and
 * DROP on behalf of Mapper. All identifiers are quoted through
 * escapeIdentifier() so that table and column names are always safe.
 *
 * @package Spot\Query
 *
 * @author  Vance Lucas <vance@vancelucas.com>
 */
class Resolver
{
    /** @var bool When true, identifier quoting is skipped (testing only). */
    protected bool $_noQuote = false;

    /**
     * @param Mapper $mapper The mapper this resolver is bound to.
     */
    public function __construct(protected readonly Mapper $mapper)
    {
    }

    /**
     * Disable identifier quoting — used for cross-platform SQL output in tests.
     */
    public function noQuote(bool $noQuote = true): static
    {
        $this->_noQuote = $noQuote;

        return $this;
    }

    /**
     * Migrate the entity's table structure to match its current field definitions.
     *
     * Creates the table if it does not yet exist, or applies ALTER TABLE
     * statements for any changes detected by DBAL's schema comparator.
     *
     * @throws \Doctrine\DBAL\Schema\SchemaException
     * @throws Exception
     *
     * @return bool|int False when no queries were run, or the result of the last executed statement.
     */
    public function migrate(): bool|int
    {
        $entity        = $this->mapper->entity();
        $table         = $entity::table();
        $connection    = $this->mapper->connection();
        $schemaManager = $connection->createSchemaManager();

        if ($schemaManager->tablesExist([$table])) {
            $currentSchema = $schemaManager->introspectSchema();
            $newSchema     = $this->migrateCreateSchema();
            $comparator    = $schemaManager->createComparator();
            $schemaDiff    = $comparator->compareSchemas($currentSchema, $newSchema);
            $queries       = $connection->getDatabasePlatform()->getAlterSchemaSQL($schemaDiff);
        } else {
            $newSchema = $this->migrateCreateSchema();
            $queries   = $newSchema->toSql($connection->getDatabasePlatform());
        }

        $lastResult = false;

        foreach ($queries as $sql) {
            $lastResult = (int) $connection->executeStatement($sql);
        }

        return $lastResult;
    }

    /**
     * Build a DBAL Schema object representing the entity's desired table structure.
     */
    public function migrateCreateSchema(): Schema
    {
        $entityName  = $this->mapper->entity();
        $table       = $entityName::table();
        $fields      = $this->mapper->entityManager()->fields();
        $fieldIndexes = $this->mapper->entityManager()->fieldKeys();

        $schema   = new Schema();
        $tableObj = $schema->createTable($this->escapeIdentifier($table));

        // DBAL4 removed legacy serialization types. Map them to 'text' for schema purposes.
        $legacyTypeMap = ['array' => 'text', 'simple_array' => 'text', 'object' => 'text'];

        foreach ($fields as $field) {
            $fieldType  = $legacyTypeMap[$field['type']] ?? $field['type'];
            $columnName = $field['column'];

            // Map Spot's 'value' (field default) to DBAL's 'default'.
            // Only scalar values can be SQL column defaults; objects/arrays are PHP-level only.
            if (isset($field['value']) && !isset($field['default']) && is_scalar($field['value'])) {
                $field['default'] = $field['value'];
            }

            // DBAL4 Column rejects unknown options. Keep only recognised DBAL column options.
            $dbalColumnOptions = array_filter(
                array_intersect_key($field, array_flip([
                    'notnull', 'default', 'autoincrement', 'unsigned', 'fixed',
                    'length', 'precision', 'scale', 'columnDefinition', 'comment',
                    'charset', 'collation', 'jsonb', 'platformOptions', 'customSchemaOptions',
                ])),
                fn ($v) => $v !== null,
            );

            $tableObj->addColumn($this->escapeIdentifier($columnName), $fieldType, $dbalColumnOptions);
        }

        if ($fieldIndexes['primary']) {
            $primaryKeys = array_values(array_map(
                fn (string $v) => $this->escapeIdentifier($v),
                $fieldIndexes['primary'],
            ));
            $tableObj->setPrimaryKey($primaryKeys);
        }

        foreach ($fieldIndexes['unique'] as $keyName => $keyFields) {
            $cols = array_values($keyFields);

            if ($cols !== []) {
                $tableObj->addUniqueIndex($cols, $this->escapeIdentifier($this->trimSchemaName($keyName)));
            }
        }

        foreach ($fieldIndexes['index'] as $keyName => $keyFields) {
            $cols = array_values($keyFields);

            if ($cols !== []) {
                $tableObj->addIndex($cols, $this->escapeIdentifier($this->trimSchemaName($keyName)));
            }
        }

        $this->addForeignKeys($tableObj);

        return $schema;
    }

    /**
     * Execute a SELECT query and return a hydrated Collection.
     *
     * @throws Exception
     */
    public function read(Query $query): Collection
    {
        $result = $query->builder()->executeQuery();
        $rows   = $result->fetchAllAssociative();
        $with   = $query->with();

        return $query->mapper()->collection(new \ArrayIterator($rows), is_array($with) ? $with : []);
    }

    /**
     * INSERT a new row into the given table.
     *
     * @param string               $table Table name.
     * @param array<string, mixed> $data  Column => value pairs.
     *
     * @return int Number of affected rows.
     */
    public function create(string $table, array $data): int
    {
        return (int) $this->mapper->connection()->insert(
            $this->escapeIdentifier($table),
            $this->dataWithFieldAliasMappings($data),
        );
    }

    /**
     * UPDATE rows matching the WHERE criteria.
     *
     * @param string               $table Table name.
     * @param array<string, mixed> $data  Columns to update.
     * @param array<string, mixed> $where WHERE criteria.
     *
     * @return int Number of affected rows.
     */
    public function update(string $table, array $data, array $where): int
    {
        return (int) $this->mapper->connection()->update(
            $this->escapeIdentifier($table),
            $this->dataWithFieldAliasMappings($data),
            $this->dataWithFieldAliasMappings($where),
        );
    }

    /**
     * Remap field names to their aliased column names for use in DBAL calls.
     *
     * @param array<string, mixed> $data Input keyed by Spot field name.
     *
     * @return array<string, mixed> Output keyed by quoted column name.
     */
    public function dataWithFieldAliasMappings(array $data): array
    {
        $fields = $this->mapper->entityManager()->fields();
        $mapped = [];

        foreach ($data as $field => $value) {
            $column          = isset($fields[$field]) ? $fields[$field]['column'] : $field;
            $mapped[$this->escapeIdentifier($column)] = $value;
        }

        return $mapped;
    }

    /**
     * Reverse-map aliased column names back to Spot field names.
     *
     * @param array<string, mixed> $data Input keyed by column name.
     *
     * @return array<string, mixed> Output keyed by Spot field name.
     */
    public function dataWithOutFieldAliasMappings(array $data): array
    {
        $this->mapper->entityManager()->fields(); // ensure mappings are populated
        $fieldAliasMappings = $this->mapper->entityManager()->fieldAliasMappings();

        foreach ($fieldAliasMappings as $field => $aliasedField) {
            if (array_key_exists($aliasedField, $data)) {
                $data[$field] = $data[$aliasedField];
                unset($data[$aliasedField]);
            }
        }

        return $data;
    }

    /**
     * Execute a DELETE or UPDATE query built on a Query object.
     *
     * @return int Number of affected rows.
     */
    public function exec(Query $query): int
    {
        return (int) $query->builder()->executeStatement();
    }

    /**
     * Truncate (empty) a table.
     *
     * SQLite does not support TRUNCATE; DELETE FROM is used instead.
     * PostgreSQL supports optional CASCADE.
     *
     * @param string $table   Table name.
     * @param bool   $cascade Whether to cascade to dependent tables (PostgreSQL only).
     *
     * @return int Number of affected rows.
     */
    public function truncate(string $table, bool $cascade = false): int
    {
        $connection  = $this->mapper->connection();
        $quotedTable = $this->escapeIdentifier($table);

        if ($this->mapper->connectionIs('sqlite')) {
            $sql = 'DELETE FROM ' . $quotedTable;
        } elseif ($this->mapper->connectionIs('pgsql')) {
            $sql = 'TRUNCATE TABLE ' . $quotedTable . ($cascade ? ' CASCADE' : '');
        } else {
            $sql = 'TRUNCATE TABLE ' . $quotedTable;
        }

        return (int) $connection->executeStatement($sql);
    }

    /**
     * Drop a table from the database.
     *
     * Returns true on success, false if the table does not exist or an error occurs.
     */
    public function dropTable(string $table): bool
    {
        try {
            $this->mapper->connection()
                ->createSchemaManager()
                ->dropTable($this->escapeIdentifier($table));

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Quote an identifier using the active DBAL connection's platform.
     *
     * When noQuote mode is enabled the identifier is returned unchanged.
     */
    public function escapeIdentifier(string $identifier): string
    {
        if ($this->_noQuote) {
            return $identifier;
        }

        return $this->mapper->connection()->quoteIdentifier(trim($identifier));
    }

    /**
     * Strip a leading schema name (e.g. "public.tablename" → "tablename").
     */
    public function trimSchemaName(string $identifier): string
    {
        $components = explode('.', $identifier, 2);

        return end($components);
    }

    /**
     * Add DBAL foreign key constraints for all BelongsTo relations.
     *
     * Automatically migrates the foreign table first if it does not yet exist
     * or is missing the required column — preventing integrity constraint errors.
     */
    protected function addForeignKeys(Table $table): Table
    {
        $entityName = $this->mapper->entity();
        $entity     = new $entityName();
        $relations  = $entityName::relations($this->mapper, $entity);
        $fields     = $this->mapper->entityManager()->fields();

        foreach ($relations as $relationName => $relation) {
            if (!($relation instanceof BelongsTo)) {
                continue;
            }

            $fieldInfo = $fields[$relation->localKey()];

            if ($fieldInfo['foreignkey'] === false) {
                continue;
            }

            $foreignTableMapper    = $relation->mapper()->getMapper($relation->entityName());
            $foreignTable          = $foreignTableMapper->table();
            $foreignSchemaManager  = $foreignTableMapper->connection()->createSchemaManager();
            $foreignTableExists    = $foreignSchemaManager->tablesExist([$foreignTable]);
            $foreignTableNotExists = !$foreignTableExists;
            $foreignKeyNotExists   = true;

            if ($foreignTableExists) {
                try {
                    $foreignTableObject  = $foreignSchemaManager->introspectTable($foreignTable);
                    $foreignKeyNotExists = !array_key_exists(
                        $relation->foreignKey(),
                        $foreignTableObject->getColumns(),
                    );
                } catch (\Exception) {
                    $foreignTableNotExists = true;
                }
            }

            $notRecursiveForeignKey = !is_a($entity, $relation->entityName());

            // If the foreign table doesn't exist yet, skip adding this FK constraint.
            // The caller is responsible for migrating dependent tables first.
            // Recursive auto-migration here can trigger circular migrate() chains.
            if ($foreignTableNotExists && $notRecursiveForeignKey) {
                continue;
            }

            if ($foreignKeyNotExists && $notRecursiveForeignKey) {
                $foreignTableMapper->migrate();
            }

            $onUpdate = $fieldInfo['onUpdate'] ?? 'CASCADE';

            if ($fieldInfo['onDelete'] !== null) {
                $onDelete = $fieldInfo['onDelete'];
            } elseif ($fieldInfo['notnull']) {
                $onDelete = 'CASCADE';
            } else {
                $onDelete = 'SET NULL';
            }

            $fieldAliasMappings = $this->mapper->entityManager()->fieldAliasMappings();
            $localKey           = $fieldAliasMappings[$relation->localKey()] ?? $relation->localKey();

            $fkName = $this->mapper->table() . '_fk_' . $relationName;
            $table->addForeignKeyConstraint(
                $foreignTable,
                [$localKey],
                [$relation->foreignKey()],
                ['onDelete' => $onDelete, 'onUpdate' => $onUpdate],
                $fkName,
            );
        }

        return $table;
    }
}
