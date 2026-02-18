<?php

declare(strict_types=1);

namespace Spot\Query\Operator;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Equality operator — exact match, IN list, or IS NULL.
 *
 * - Array value   → column IN (...)
 * - null value    → column IS NULL
 * - Scalar value  → column = ?
 *
 * @package Spot\Query\Operator
 */
class Equals
{
    /**
     * Build an equality SQL fragment with bound parameters.
     *
     * @param QueryBuilder $builder The DBAL query builder.
     * @param string       $column  The pre-quoted column expression.
     * @param mixed        $value   Scalar, null, or array.
     *
     * @return string SQL fragment.
     */
    public function __invoke(QueryBuilder $builder, string $column, mixed $value): string
    {
        if (is_array($value) && !empty($value)) {
            return $column . ' IN (' . $builder->createPositionalParameter($value, ArrayParameterType::STRING) . ')';
        }

        if ($value === null || (is_array($value) && empty($value))) {
            return $column . ' IS NULL';
        }

        return $column . ' = ' . $builder->createPositionalParameter($value);
    }
}
