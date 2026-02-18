<?php

declare(strict_types=1);

namespace Spot\Query\Operator;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * FULLTEXT search operator — MySQL/MariaDB natural language mode.
 *
 * Requires a FULLTEXT index on the target column(s) and a MyISAM or
 * InnoDB (5.6+) table engine.
 *
 * @package Spot\Query\Operator
 */
class FullText
{
    /**
     * Build a MATCH ... AGAINST SQL fragment with a bound parameter.
     *
     * @param QueryBuilder $builder The DBAL query builder.
     * @param string       $column  The pre-quoted column expression.
     * @param mixed        $value   The search string.
     *
     * @return string SQL fragment.
     */
    public function __invoke(QueryBuilder $builder, string $column, mixed $value): string
    {
        return 'MATCH(' . $column . ') AGAINST (' . $builder->createPositionalParameter($value) . ')';
    }
}
