<?php

declare(strict_types=1);

namespace Spot;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Query Object - Used to build adapter-independent queries PHP-style.
 *
 * Wraps DBAL's QueryBuilder and provides Spot-specific field aliasing,
 * operator dispatch, eager-loading hints, and SPL collection interfaces.
 *
 * @package Spot
 *
 * @author  Vance Lucas <vance@vancelucas.com>
 *
 * @implements \IteratorAggregate<int, EntityInterface>
 * @implements \ArrayAccess<int, EntityInterface>
 */
class Query implements \Countable, \IteratorAggregate, \ArrayAccess, \JsonSerializable
{
    public const ALL_FIELDS = '*';

    /**
     * Custom methods added by extensions or plugins.
     *
     * @var array<string, callable>
     */
    protected static array $_customMethods = [];

    /**
     * Map of operator strings to operator class names or callables.
     *
     * @var array<string, callable|string>
     */
    protected static array $_whereOperators = [
        '<'                 => 'Spot\Query\Operator\LessThan',
        ':lt'               => 'Spot\Query\Operator\LessThan',
        '<='                => 'Spot\Query\Operator\LessThanOrEqual',
        ':lte'              => 'Spot\Query\Operator\LessThanOrEqual',
        '>'                 => 'Spot\Query\Operator\GreaterThan',
        ':gt'               => 'Spot\Query\Operator\GreaterThan',
        '>='                => 'Spot\Query\Operator\GreaterThanOrEqual',
        ':gte'              => 'Spot\Query\Operator\GreaterThanOrEqual',
        '~='                => 'Spot\Query\Operator\RegExp',
        '=~'                => 'Spot\Query\Operator\RegExp',
        ':regex'            => 'Spot\Query\Operator\RegExp',
        ':like'             => 'Spot\Query\Operator\Like',
        ':notlike'          => 'Spot\Query\Operator\NotLike',
        ':fulltext'         => 'Spot\Query\Operator\FullText',
        ':fulltext_boolean' => 'Spot\Query\Operator\FullTextBoolean',
        'in'                => 'Spot\Query\Operator\In',
        ':in'               => 'Spot\Query\Operator\In',
        '<>'                => 'Spot\Query\Operator\Not',
        '!='                => 'Spot\Query\Operator\Not',
        ':ne'               => 'Spot\Query\Operator\Not',
        ':not'              => 'Spot\Query\Operator\Not',
        '='                 => 'Spot\Query\Operator\Equals',
        ':eq'               => 'Spot\Query\Operator\Equals',
    ];

    /**
     * Already-instantiated operator objects (flyweight cache).
     *
     * @var array<string, object>
     */
    protected static array $_whereOperatorObjects = [];

    /** @var string[] Allowed sort directions (whitelist). */
    private static array $allowedSortDirections = ['ASC', 'DESC'];

    protected Mapper $_mapper;

    protected string $_entityName;

    protected string $_tableName;

    protected QueryBuilder $_queryBuilder;

    protected bool $_noQuote = false;

    /**
     * Eager-load relation names.
     *
     * @var array<string>
     */
    protected array $with = [];

    /**
     * Storage for eager-loaded relation data.
     *
     * @var array<mixed>
     */
    protected array $_data = [];

    /**
     * @param Mapper $mapper The mapper this query is scoped to.
     *
     * @throws Exception
     */
    public function __construct(Mapper $mapper)
    {
        $this->_mapper = $mapper;
        $this->_entityName = $mapper->entity();
        $this->_tableName = $mapper->table();
        $this->_queryBuilder = $mapper->connection()->createQueryBuilder();
    }

    /**
     * Handle calls to undefined methods — checks custom methods, scopes,
     * and Collection passthroughs.
     *
     * @param string       $method Method name.
     * @param array<mixed> $args   Arguments.
     *
     * @throws \BadMethodCallException When no handler is found.
     */
    public function __call(string $method, array $args): mixed
    {
        $scopes = $this->mapper()->scopes();

        if (isset(self::$_customMethods[$method]) && is_callable(self::$_customMethods[$method])) {
            array_unshift($args, $this);

            return call_user_func_array(self::$_customMethods[$method], $args);
        }

        if (isset($scopes[$method])) {
            array_unshift($args, $this);

            return call_user_func_array($scopes[$method], $args);
        }

        if (method_exists(Entity\Collection::class, $method)) {
            $collection = $this->execute();

            if ($collection === false) {
                return null;
            }

            /** @var callable(mixed...): mixed $callable */
            $callable = [$collection, $method];

            return $callable(...$args);
        }

        throw new \BadMethodCallException("Method '" . __CLASS__ . '::' . $method . "' not found");
    }

    /**
     * Register a custom method on all Query instances.
     *
     * @param string   $method   Method name to register.
     * @param callable $callback Callback invoked when the method is called.
     *
     * @throws \InvalidArgumentException When the method name already exists.
     */
    public static function addMethod(string $method, callable $callback): void
    {
        if (method_exists(__CLASS__, $method)) {
            throw new \InvalidArgumentException("Method '" . $method . "' already exists on " . __CLASS__);
        }

        self::$_customMethods[$method] = $callback;
    }

    /**
     * Register a custom WHERE operator.
     *
     * @param string          $operator Operator token (e.g. ':between').
     * @param callable|string $action   Callable or operator class name.
     *
     * @throws \InvalidArgumentException When the operator already exists.
     */
    public static function addWhereOperator(string $operator, callable|string $action): void
    {
        if (isset(self::$_whereOperators[$operator])) {
            throw new \InvalidArgumentException("Where operator '" . $operator . "' already exists");
        }

        static::$_whereOperators[$operator] = $action;
    }

    /**
     * Get the underlying DBAL QueryBuilder instance.
     */
    public function builder(): QueryBuilder
    {
        return $this->_queryBuilder;
    }

    /**
     * Disable identifier quoting — used for testing SQL output across platforms.
     */
    public function noQuote(bool $noQuote = true): static
    {
        $this->_noQuote = $noQuote;

        return $this;
    }

    /**
     * Return the DBAL expression builder.
     */
    public function expr(): \Doctrine\DBAL\Query\Expression\ExpressionBuilder
    {
        return $this->builder()->expr();
    }

    /**
     * Get the mapper this query is bound to.
     */
    public function mapper(): Mapper
    {
        return $this->_mapper;
    }

    /**
     * Get the entity class name this query targets.
     */
    public function entityName(): string
    {
        return $this->_entityName;
    }

    /**
     * SELECT columns (passthrough to DBAL QueryBuilder).
     */
    public function select(): static
    {
        call_user_func_array([$this->builder(), 'select'], (array) $this->escapeIdentifier(func_get_args()));

        return $this;
    }

    /**
     * DELETE (passthrough to DBAL QueryBuilder).
     */
    public function delete(): static
    {
        call_user_func_array([$this->builder(), 'delete'], (array) $this->escapeIdentifier(func_get_args()));

        return $this;
    }

    /**
     * FROM clause (passthrough to DBAL QueryBuilder).
     */
    public function from(): static
    {
        call_user_func_array([$this->builder(), 'from'], (array) $this->escapeIdentifier(func_get_args()));

        return $this;
    }

    /**
     * Get all bound query parameters (passthrough to DBAL QueryBuilder).
     *
     * @return array<mixed>
     */
    public function getParameters(): array
    {
        return $this->builder()->getParameters();
    }

    /**
     * Set query parameters (passthrough to DBAL QueryBuilder).
     *
     * @param array<mixed> $params
     */
    public function setParameters(array $params): static
    {
        $this->builder()->setParameters($params);

        return $this;
    }

    /**
     * Add AND WHERE conditions.
     *
     * @param array<string, mixed> $where Conditions keyed by "field [operator]".
     * @param string               $type  Logical join: 'AND' or 'OR'.
     */
    public function where(array $where, string $type = 'AND'): static
    {
        if (!empty($where)) {
            $whereClause = implode(' ' . $type . ' ', $this->parseWhereToSQLFragments($where));
            $this->builder()->andWhere($whereClause);
        }

        return $this;
    }

    /**
     * Add OR WHERE conditions.
     *
     * @param array<string, mixed> $where Conditions keyed by "field [operator]".
     * @param string               $type  Logical join inside this group: 'AND' or 'OR'.
     */
    public function orWhere(array $where, string $type = 'AND'): static
    {
        if (!empty($where)) {
            $whereClause = implode(' ' . $type . ' ', $this->parseWhereToSQLFragments($where));
            $this->builder()->orWhere($whereClause);
        }

        return $this;
    }

    /**
     * Alias for where() — adds AND WHERE conditions.
     *
     * @param array<string, mixed> $where
     */
    public function andWhere(array $where, string $type = 'AND'): static
    {
        return $this->where($where, $type);
    }

    /**
     * Add a WHERE condition using a raw SQL fragment for the value side.
     *
     * @param string       $field  Field name (will be quoted).
     * @param string       $sql    Raw SQL for the right-hand side; use ? for placeholders.
     * @param array<mixed> $params Values to bind to each ? placeholder.
     *
     * @throws Exception When placeholder and parameter counts don't match.
     */
    public function whereFieldSql(string $field, string $sql, array $params = []): static
    {
        $builder = $this->builder();
        $placeholderCount = substr_count($sql, '?');
        $paramCount = count($params);

        if ($placeholderCount !== $paramCount) {
            throw new Exception(
                'Number of supplied parameters (' . $paramCount . ') does not match '
                . 'the number of provided placeholders (' . $placeholderCount . ')',
            );
        }

        $sql = preg_replace_callback('/\?/', function () use ($builder, &$params) {
            return $builder->createPositionalParameter(array_shift($params));
        }, $sql);

        $builder->andWhere((string) $this->escapeIdentifier($field) . ' ' . $sql);

        return $this;
    }

    /**
     * Add a raw SQL WHERE expression (no escaping applied).
     *
     * @param string $sql Raw SQL expression.
     */
    public function whereSql(string $sql): static
    {
        $this->builder()->andWhere($sql);

        return $this;
    }

    /**
     * Specify relations to eager-load with this query.
     *
     * Called with no arguments it returns the current with-list.
     *
     * @param array<string>|string|null $relations Relation name(s) to eager-load.
     *
     * @return static|array<string>
     */
    public function with(array|string|null $relations = null): static|array
    {
        if ($relations === null) {
            return $this->with;
        }

        $this->with = array_unique(array_merge((array) $relations, $this->with));

        return $this;
    }

    /**
     * Perform a LIKE-based search across one or more fields.
     *
     * Each field is properly quoted via quoteIdentifier. FULLTEXT is not
     * auto-selected here because it requires knowing the storage engine at
     * runtime; use the :fulltext operator explicitly when needed.
     *
     * @param array<string>|string $fields  Field name(s) to search.
     * @param string               $query   Search string.
     * @param array<string, mixed> $options Reserved for future use.
     */
    public function search(array|string $fields, string $query, array $options = []): static
    {
        $fields = (array) $fields;

        // Quote each field properly using the platform-aware quoteIdentifier
        $quotedFields = array_map(
            fn (string $f) => $this->mapper()->connection()->quoteIdentifier($f),
            $fields,
        );
        $fieldString = implode(', ', $quotedFields);

        return $this->where([$fieldString . ' :like' => $query]);
    }

    /**
     * ORDER BY clause.
     *
     * @param array<string, string> $order Field => direction ('ASC'|'DESC') pairs.
     *
     * @throws \InvalidArgumentException When an invalid sort direction is supplied.
     */
    public function order(array $order): static
    {
        foreach ($order as $field => $sorting) {
            $direction = strtoupper((string) $sorting);

            if (!in_array($direction, self::$allowedSortDirections, true)) {
                throw new \InvalidArgumentException(
                    "Invalid sort direction '" . $sorting . "'. Allowed: ASC, DESC.",
                );
            }

            $this->builder()->addOrderBy($this->fieldWithAlias($field), $direction);
        }

        return $this;
    }

    /**
     * GROUP BY clause.
     *
     * @param array<string> $fields Field names to group by.
     */
    public function group(array $fields = []): static
    {
        foreach ($fields as $field) {
            $this->builder()->addGroupBy($this->fieldWithAlias($field));
        }

        return $this;
    }

    /**
     * HAVING clause — filter by aggregate conditions.
     *
     * @param array<string, mixed> $having Conditions (same format as where()).
     * @param string               $type   Logical join: 'AND' or 'OR'.
     */
    public function having(array $having, string $type = 'AND'): static
    {
        $this->builder()->having(
            implode(' ' . $type . ' ', $this->parseWhereToSQLFragments($having, false)),
        );

        return $this;
    }

    /**
     * LIMIT — restrict result set size.
     *
     * @param int      $limit  Maximum number of rows.
     * @param int|null $offset Optional row offset.
     */
    public function limit(int $limit, ?int $offset = null): static
    {
        if ($limit > 0) {
            $this->builder()->setMaxResults($limit);

            if ($offset !== null) {
                $this->offset($offset);
            }
        }

        return $this;
    }

    /**
     * OFFSET — skip rows at the start of the result set.
     *
     * @param int $offset Number of rows to skip.
     */
    public function offset(int $offset): static
    {
        $this->builder()->setFirstResult($offset);

        return $this;
    }

    // =========================================================================
    // SPL interfaces
    // =========================================================================

    /**
     * SPL Countable — execute a COUNT(*) query and return the total.
     *
     * Order clauses are stripped as they don't affect counts.
     */
    public function count(): int
    {
        $countCopy = clone $this->builder();
        $result = $countCopy->select('COUNT(*)')->resetOrderBy()->executeQuery();

        return max(0, (int) $result->fetchOne());
    }

    /**
     * SPL IteratorAggregate — execute and return collection for foreach iteration.
     *
     * @return Entity\Collection
     */
    public function getIterator(): \Traversable
    {
        $result = $this->execute();

        return ($result !== false) ? $result : new Entity\Collection();
    }

    /**
     * Return results as a plain array.
     *
     * @param string|null $keyColumn   Optional column to use as array key.
     * @param string|null $valueColumn Optional column to use as array value.
     *
     * @return array<mixed>
     */
    public function toArray(?string $keyColumn = null, ?string $valueColumn = null): array
    {
        $result = $this->execute();

        return ($result !== false) ? $result->toArray($keyColumn, $valueColumn) : [];
    }

    /**
     * JsonSerializable — serialize the collection to a JSON-compatible array.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Return the first entity matching the query, or false if none found.
     */
    public function first(): EntityInterface|false
    {
        $result = $this->limit(1)->execute();

        return ($result !== false) ? $result->first() : false;
    }

    /**
     * Execute the query and return the resulting Collection.
     */
    public function execute(): Entity\Collection|false
    {
        return $this->mapper()->resolver()->read($this);
    }

    /**
     * Return the raw SQL string that would be executed.
     *
     * When noQuote() is enabled the platform quote characters are stripped so
     * the SQL is readable across drivers.
     */
    public function toSql(): string
    {
        if ($this->_noQuote) {
            // Strip all common SQL identifier quote characters for readable test output.
            // DBAL4 removed getIdentifierQuoteCharacter() from AbstractPlatform;
            // we simply strip the three possible chars (`, ", [, ]) directly.
            $sql = $this->builder()->getSQL();

            return str_replace(['`', '"', '[', ']'], '', $sql);
        }

        return $this->builder()->getSQL();
    }

    /**
     * Quote a scalar value for safe embedding in SQL.
     *
     * Prefer parameterised queries over this method wherever possible.
     *
     * @param string $string Value to quote.
     */
    public function escape(string $string): string
    {
        if ($this->_noQuote) {
            return $string;
        }

        return $this->mapper()->connection()->quote($string);
    }

    /**
     * Strip the platform quote character from a quoted identifier.
     *
     * @param string $identifier Possibly-quoted identifier or dot-separated path.
     */
    public function unescapeIdentifier(string $identifier): string
    {
        if (str_contains($identifier, '.')) {
            $parts = array_map([$this, 'unescapeIdentifier'], explode('.', $identifier));

            return implode('.', $parts);
        }

        // Strip any of the three common SQL identifier quote characters.
        return trim($identifier, '"`[]');
    }

    /**
     * Quote an identifier (column/table name) using the active platform.
     *
     * Arrays are processed recursively. Complex expressions containing spaces
     * or parentheses are returned as-is.
     *
     * @param string|array<string> $identifier
     *
     * @return string|array<string>
     */
    /**
     * Quote an identifier (column/table name) or an array of identifiers.
     *
     * @param string|array<string> $identifier
     * @return ($identifier is array ? array<string> : string)
     */
    public function escapeIdentifier(string|array $identifier): string|array
    {
        if (is_array($identifier)) {
            array_walk($identifier, function (&$id) {
                $id = $this->escapeIdentifier($id);
            });

            return $identifier;
        }

        if ($this->_noQuote || $identifier === self::ALL_FIELDS) {
            return $identifier;
        }

        if (str_contains($identifier, ' ') || str_contains($identifier, '(')) {
            return $identifier; // complex expression — do not quote
        }

        return $this->mapper()->connection()->quoteIdentifier(trim($identifier));
    }

    /**
     * Return the fully-qualified, quoted column name for a field.
     *
     * Handles column aliases defined in the entity and SQL function wrapping
     * (e.g. TRIM(fieldname)).
     *
     * @param string $field   Field name or function-wrapped expression.
     * @param bool   $escaped Whether to quote the resulting identifier.
     */
    public function fieldWithAlias(string $field, bool $escaped = true): string
    {
        $fieldInfo = $this->_mapper->entityManager()->fields();

        $field = trim($field);
        $functionStart = '';
        $functionEnd = '';
        $functionPos = strpos($field, '(');

        if ($functionPos !== false) {
            foreach ($fieldInfo as $key => $currentField) {
                $fieldFound = strpos($field, $key);

                if ($fieldFound !== false) {
                    $functionStart = substr($field, 0, $fieldFound);
                    $functionEnd = substr($field, $fieldFound + strlen($key));
                    $field = $key;
                    break;
                }
            }
        }

        // Resolve column alias
        if (isset($fieldInfo[$field])) {
            $field = $fieldInfo[$field]['column'];
        }

        $field = $this->_tableName . '.' . $field;
        $field = $escaped ? (string) $this->escapeIdentifier($field) : $field;

        return $functionStart . $field . $functionEnd;
    }

    // =========================================================================
    // SPL ArrayAccess
    // =========================================================================

    /** @inheritdoc */
    public function offsetExists(mixed $offset): bool
    {
        $results = $this->execute();

        return $results !== false && isset($results[$offset]);
    }

    /** @inheritdoc */
    public function offsetGet(mixed $offset): mixed
    {
        $results = $this->execute();

        return $results !== false ? $results[$offset] : null;
    }

    /** @inheritdoc */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $results = $this->execute();

        if ($results === false) {
            return;
        }

        if ($offset === null) {
            $results[] = $value;
        } else {
            $results[$offset] = $value;
        }
    }

    /** @inheritdoc */
    public function offsetUnset(mixed $offset): void
    {
        $results = $this->execute();

        if ($results !== false) {
            unset($results[$offset]);
        }
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    /**
     * Parse the array-syntax WHERE conditions into DBAL SQL fragment strings.
     *
     * Keys follow the pattern "fieldName [operator]"; if no operator is given
     * the Equals operator is used.
     *
     * @param array<string, mixed> $where    Conditions to parse.
     * @param bool                 $useAlias Whether to prefix fields with the table alias.
     *
     * @throws \InvalidArgumentException When an unknown operator is used.
     *
     * @return array<string> SQL fragment strings.
     */
    private function parseWhereToSQLFragments(array $where, bool $useAlias = true): array
    {
        $builder = $this->builder();
        $sqlFragments = [];

        foreach ($where as $column => $value) {
            $colData = explode(' ', $column);
            $operator = $colData[1] ?? '=';

            if (count($colData) > 2) {
                $operator = array_pop($colData);
                $colData = [implode(' ', $colData), $operator];
            }

            $operatorCallable = $this->getWhereOperatorCallable(strtolower($operator));

            if (!$operatorCallable) {
                throw new \InvalidArgumentException(
                    "Unsupported operator '" . $operator . "' in WHERE clause. "
                    . 'Register custom operators with Spot\\Query::addWhereOperator().',
                );
            }

            $col = $colData[0];

            if ($value instanceof \DateTime) {
                $mapper = $this->mapper();
                $convertedValues = $mapper->convertToDatabaseValues($mapper->entity(), [$col => $value]);
                $value = $convertedValues[$col];
            }

            if ($useAlias === true) {
                $col = $this->fieldWithAlias($col);
            }

            $sqlFragments[] = $operatorCallable($builder, $col, $value);
        }

        return $sqlFragments;
    }

    /**
     * Resolve an operator token to a callable (with flyweight caching).
     *
     * @param string $operator Normalised (lower-cased) operator token.
     *
     * @return callable|false The operator callable, or false when not found.
     */
    private function getWhereOperatorCallable(string $operator): callable|false
    {
        if (!isset(static::$_whereOperators[$operator])) {
            return false;
        }

        $entry = static::$_whereOperators[$operator];

        if (is_callable($entry) && !is_string($entry)) {
            return $entry;
        }

        // At this point $entry is a class-name string (narrowed by PHPStan)
        if (!isset(static::$_whereOperatorObjects[$operator])) {
            if (!class_exists($entry)) {
                return false;
            }

            static::$_whereOperatorObjects[$operator] = new $entry();
        }

        /** @var callable&object */
        return static::$_whereOperatorObjects[$operator];
    }
}
