<?php

declare(strict_types=1);

namespace Spot\Entity;

use Spot;

/**
 * Entity Manager — stores and caches metadata about entity classes.
 *
 * Resolves field definitions, default values, index declarations, relation
 * definitions, and primary key information for a single entity class.
 *
 * @package Spot\Entity
 *
 * @author Vance Lucas <vance@vancelucas.com>
 */
class Manager
{
    protected string $entityName;

    /** @var array<string, array<string, mixed>> */
    protected array $fields = [];

    /** @var array<string, string> */
    protected array $fieldAliasMappings = [];

    /** @var array<string, array<string, mixed>> */
    protected array $fieldsDefined = [];

    /** @var array<string, mixed> */
    protected array $fieldDefaultValues = [];

    /** @var array<string, mixed> */
    protected array $relations = [];

    /** @var array<string, callable> */
    protected array $scopes = [];

    protected string|false $primaryKeyField = false;

    protected string $table = '';

    /** Connection name */
    protected ?string $connection = null;

    /** @var array<string, mixed> */
    protected array $tableOptions = [];

    protected string|false $mapper = false;

    /**
     * @param string $entityName Fully-qualified entity class name.
     *
     * @throws \Spot\Exception When class is not a valid Spot\Entity subclass.
     */
    public function __construct(string $entityName)
    {
        if (!is_subclass_of($entityName, \Spot\Entity::class)) {
            throw new \Spot\Exception($entityName . " must be subclass of '\\Spot\\Entity'.");
        }

        $this->entityName = $entityName;
    }

    /**
     * Get formatted fields with all necessary array keys and values.
     *
     * Merges defaults with defined field values to ensure all options exist for each field.
     *
     * @return array<string, array<string, mixed>> Defined fields plus all defaults.
     */
    public function fields(): array
    {
        $entityName = $this->entityName;

        if (!empty($this->fields)) {
            return $this->fields;
        }

        // Table info
        $entityTable = $entityName::table();

        if ($entityTable === null || !is_string($entityTable)) {
            throw new \InvalidArgumentException(
                "Entity must have a table defined. Please define a protected static property named 'table' on your '"
                . $entityName . "' entity class.",
            );
        }

        $this->table      = $entityTable;
        $this->connection = $entityName::connection();
        $this->tableOptions = (array) $entityName::tableOptions();
        $this->mapper     = $entityName::mapper();

        // Default settings for all fields
        $fieldDefaults = [
            'type'          => 'string',
            'default'       => null,
            'value'         => null,
            'length'        => null,
            'column'        => null,
            'required'      => false,
            'notnull'       => false,
            'unsigned'      => false,
            'fulltext'      => false,
            'primary'       => false,
            'index'         => false,
            'unique'        => false,
            'autoincrement' => false,
            'foreignkey'    => true,
            'onUpdate'      => null,
            'onDelete'      => null,
        ];

        // Type default overrides for specific field types
        $fieldTypeDefaults = [
            'string' => [
                'length' => 255,
            ],
            'float' => [
                'length' => [10, 2],
            ],
            'integer' => [
                'length'   => 10,
                'unsigned' => true,
            ],
        ];

        $entityFields = $entityName::fields();

        if (!is_array($entityFields) || count($entityFields) < 1) {
            throw new \InvalidArgumentException($entityName . ' must have at least one field defined.');
        }

        $returnFields             = [];
        $this->fieldDefaultValues = [];

        foreach ($entityFields as $fieldName => $fieldOpts) {
            // Store field definition exactly how it is defined before modifying it below
            $this->fieldsDefined[$fieldName] = $fieldOpts;

            // Format field with full set of default options
            if (isset($fieldOpts['type'], $fieldTypeDefaults[$fieldOpts['type']])) {
                $fieldOpts = array_merge($fieldDefaults, $fieldTypeDefaults[$fieldOpts['type']], $fieldOpts);
            } else {
                $fieldOpts = array_merge($fieldDefaults, $fieldOpts);
            }

            // required = 'notnull' for DBAL unless manually set in schema
            if ($fieldOpts['required'] === true) {
                $fieldOpts['notnull'] = $this->fieldsDefined[$fieldName]['notnull'] ?? true;
            }

            // Set column name to field name/key as default
            if ($fieldOpts['column'] === null) {
                $fieldOpts['column'] = $fieldName;
            } else {
                // Store user-specified field alias mapping
                $this->fieldAliasMappings[$fieldName] = $fieldOpts['column'];
            }

            // Old Spot used 'serial' field to describe auto-increment fields
            if (isset($fieldOpts['serial']) && $fieldOpts['serial'] === true) {
                $fieldOpts['primary']       = true;
                $fieldOpts['autoincrement'] = true;
            }

            // Store primary key
            if ($fieldOpts['primary'] === true || $fieldOpts['autoincrement'] === true) {
                $this->primaryKeyField = $fieldName;
            }

            // Store default value
            if ($fieldOpts['value'] !== null) {
                $this->fieldDefaultValues[$fieldName] = $fieldOpts['value'];
            } elseif ($fieldOpts['default'] !== null) {
                $this->fieldDefaultValues[$fieldName] = $fieldOpts['default'];
            } else {
                $this->fieldDefaultValues[$fieldName] = null;
            }

            $returnFields[$fieldName] = $fieldOpts;
        }

        $this->fields = $returnFields;

        return $returnFields;
    }

    /**
     * Field alias mappings (used for lookup).
     *
     * @return array<string, string> Field alias => actual column name.
     */
    public function fieldAliasMappings(): array
    {
        return $this->fieldAliasMappings;
    }

    /**
     * Groups field keys into named arrays of fields with key name as index.
     *
     * @return array{primary: array<string>, unique: array<string, array<string>>, index: array<string, array<string>>}
     */
    public function fieldKeys(): array
    {
        $entityName     = $this->entityName;
        $table          = $entityName::table();
        $formattedFields = $this->fields();

        $ki       = 0;
        $tableKeys = [
            'primary' => [],
            'unique'  => [],
            'index'   => [],
        ];
        $usedKeyNames = [];

        foreach ($formattedFields as $fieldInfo) {
            $fieldName    = $fieldInfo['column'];
            $fieldKeyName = $table . '_' . $fieldName;

            while (in_array($fieldKeyName, $usedKeyNames, true)) {
                $fieldKeyName = $fieldName . '_' . $ki;
            }

            if ($fieldInfo['primary']) {
                $tableKeys['primary'][] = $fieldName;
            }

            if ($fieldInfo['unique']) {
                $fieldInfo['unique'] = (array) $fieldInfo['unique'];

                foreach ($fieldInfo['unique'] as $fieldInfoUnique) {
                    $fieldKeyName = $this->computeIndexName($table, $fieldInfoUnique) ?: $fieldKeyName;
                    $tableKeys['unique'][$fieldKeyName][] = $fieldName;
                    $usedKeyNames[] = $fieldKeyName;
                }
            }

            if ($fieldInfo['index']) {
                $fieldInfo['index'] = (array) $fieldInfo['index'];

                foreach ($fieldInfo['index'] as $fieldInfoIndex) {
                    $fieldKeyName = $this->computeIndexName($table, $fieldInfoIndex) ?: $fieldKeyName;
                    $tableKeys['index'][$fieldKeyName][] = $fieldName;
                    $usedKeyNames[] = $fieldKeyName;
                }
            }
        }

        return $tableKeys;
    }

    /**
     * Get field information exactly how it is defined in the class.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fieldsDefined(): array
    {
        if (empty($this->fieldsDefined)) {
            $this->fields();
        }

        return $this->fieldsDefined;
    }

    /**
     * Get field default values as defined in class field definitions.
     *
     * @return array<string, mixed>
     */
    public function fieldDefaultValues(): array
    {
        if (empty($this->fieldDefaultValues)) {
            $this->fields();
        }

        return $this->fieldDefaultValues;
    }

    /**
     * Reset cached field data (forces re-population on next access).
     */
    public function resetFields(): void
    {
        $this->fields             = [];
        $this->fieldsDefined      = [];
        $this->fieldDefaultValues = [];
        $this->relations          = [];
        $this->primaryKeyField    = false;
    }

    /**
     * Get defined relations.
     *
     * @return array<string, mixed>
     */
    public function relations(): array
    {
        $this->fields();

        return $this->relations ?: [];
    }

    /**
     * Get value of primary key for given row result.
     */
    public function primaryKeyField(): string|false
    {
        if ($this->primaryKeyField === false) {
            $this->fields();
        }

        return $this->primaryKeyField;
    }

    /**
     * Check if field exists in defined fields.
     */
    public function fieldExists(string $field): bool
    {
        return array_key_exists($field, $this->fields());
    }

    /**
     * Return field type.
     *
     * @return string|false Field type string or false when field not found.
     */
    public function fieldType(string $field): string|false
    {
        $fields = $this->fields();

        return $this->fieldExists($field) ? $fields[$field]['type'] : false;
    }

    /**
     * Get name of table for given entity class.
     */
    public function table(): string
    {
        if ($this->table === '') {
            $this->fields();
        }

        return $this->table;
    }

    /**
     * Get name of db connection for given entity class.
     */
    public function connection(): ?string
    {
        if ($this->connection === null) {
            $this->fields();
        }

        return $this->connection;
    }

    /**
     * Get table options for given entity class.
     *
     * @return array<string, mixed>
     */
    public function tableOptions(): array
    {
        if (empty($this->tableOptions)) {
            $this->fields();
        }

        return $this->tableOptions;
    }

    /**
     * Get name of custom mapper for given entity class.
     */
    public function mapper(): string|false
    {
        if ($this->mapper === false) {
            $this->fields();
        }

        return $this->mapper;
    }

    /**
     * Attempt to generate a user-suffixed index name.
     */
    private function computeIndexName(string $table, string|bool $indexConfiguration): string|false
    {
        if (is_string($indexConfiguration)) {
            return $table . '_' . $indexConfiguration;
        }

        return false;
    }
}
