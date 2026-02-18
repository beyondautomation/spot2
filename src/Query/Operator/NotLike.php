<?php

declare(strict_types=1);

namespace Spot\Query\Operator;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * NOT LIKE operator — negative pattern matching.
 *
 * @package Spot\Query\Operator
 */
class NotLike
{
    /**
     * Build a NOT LIKE SQL fragment with a bound parameter.
     *
     * @param QueryBuilder $builder The DBAL query builder.
     * @param string       $column  The pre-quoted column expression.
     * @param mixed        $value   The LIKE pattern (e.g. '%foo%').
     *
     * @return string SQL fragment.
     */
    public function __invoke(QueryBuilder $builder, string $column, mixed $value): string
    {
        return $column . ' NOT LIKE ' . $builder->createPositionalParameter($value);
    }
}
