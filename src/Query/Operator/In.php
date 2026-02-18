<?php

declare(strict_types=1);

namespace Spot\Query\Operator;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Spot\Exception;

/**
 * IN operator — matches any value in the provided array.
 *
 * @package Spot\Query\Operator
 */
class In
{
    /**
     * Build an IN(...) SQL fragment with bound parameters.
     *
     * @param QueryBuilder $builder The DBAL query builder.
     * @param string       $column  The pre-quoted column expression.
     * @param mixed        $value   Must be a non-empty array.
     *
     * @throws Exception When $value is not an array.
     *
     * @return string SQL fragment.
     */
    public function __invoke(QueryBuilder $builder, string $column, mixed $value): string
    {
        if (!is_array($value)) {
            throw new Exception('Use of IN operator expects value to be array. Got ' . gettype($value) . '.');
        }

        return $column . ' IN (' . $builder->createPositionalParameter($value, ArrayParameterType::STRING) . ')';
    }
}
