<?php

declare(strict_types=1);

namespace Spot\Query\Operator;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * LIKE operator — pattern matching with % wildcards.
 *
 * @package Spot\Query\Operator
 */
class Like
{
    /**
     * Build a LIKE SQL fragment with a bound parameter.
     *
     * @param QueryBuilder $builder The DBAL query builder.
     * @param string       $column  The pre-quoted column expression.
     * @param mixed        $value   The LIKE pattern (e.g. '%foo%').
     *
     * @return string SQL fragment.
     */
    public function __invoke(QueryBuilder $builder, string $column, mixed $value): string
    {
        return $column . ' LIKE ' . $builder->createPositionalParameter($value);
    }
}
