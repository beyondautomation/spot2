<?php

declare(strict_types=1);

namespace Spot\Query\Operator;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * NOT / NOT IN operator — inequality or exclusion from an array.
 *
 * - Array value   → column NOT IN (...)
 * - null value    → column IS NOT NULL
 * - Scalar value  → column != ?
 *
 * @package Spot\Query\Operator
 */
class Not
{
    /**
     * Build a NOT / NOT IN SQL fragment with bound parameters.
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
            return $column . ' NOT IN (' . $builder->createPositionalParameter($value, ArrayParameterType::STRING) . ')';
        }

        if ($value === null || (is_array($value) && empty($value))) {
            return $column . ' IS NOT NULL';
        }

        return $column . ' != ' . $builder->createPositionalParameter($value);
    }
}
