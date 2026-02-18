<?php

declare(strict_types=1);

namespace Spot\Query\Operator;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * REGEXP operator — regular expression matching (MySQL/MariaDB only).
 *
 * @package Spot\Query\Operator
 */
class RegExp
{
    /**
     * Build a REGEXP SQL fragment with a bound parameter.
     *
     * Note: REGEXP support is driver-specific. MySQL and MariaDB support it
     * natively; SQLite requires a user-defined function; PostgreSQL uses ~.
     *
     * @param QueryBuilder $builder The DBAL query builder.
     * @param string       $column  The pre-quoted column expression.
     * @param mixed        $value   The regular expression pattern.
     *
     * @return string SQL fragment.
     */
    public function __invoke(QueryBuilder $builder, string $column, mixed $value): string
    {
        return $column . ' REGEXP ' . $builder->createPositionalParameter($value);
    }
}
