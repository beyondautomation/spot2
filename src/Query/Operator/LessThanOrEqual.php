<?php

declare(strict_types=1);

namespace Spot\Query\Operator;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Less-than-or-equal operator (<=).
 *
 * @package Spot\Query\Operator
 */
class LessThanOrEqual
{
    /**
     * Build a <= SQL fragment with a bound parameter.
     *
     * @param QueryBuilder $builder The DBAL query builder.
     * @param string       $column  The pre-quoted column expression.
     * @param mixed        $value   The comparison value.
     *
     * @return string SQL fragment.
     */
    public function __invoke(QueryBuilder $builder, string $column, mixed $value): string
    {
        return $column . ' <= ' . $builder->createPositionalParameter($value);
    }
}
