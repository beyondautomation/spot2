<?php

declare(strict_types=1);

namespace Spot\Query\Operator;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * FULLTEXT Boolean search operator — MySQL/MariaDB boolean mode.
 *
 * Supports boolean operators (+, -, *, ") in the search string.
 * Requires a FULLTEXT index on the target column(s).
 *
 * @package Spot\Query\Operator
 */
class FullTextBoolean
{
    /**
     * Build a MATCH ... AGAINST ... IN BOOLEAN MODE SQL fragment.
     *
     * @param QueryBuilder $builder The DBAL query builder.
     * @param string       $column  The pre-quoted column expression.
     * @param mixed        $value   The boolean-mode search string.
     *
     * @return string SQL fragment.
     */
    public function __invoke(QueryBuilder $builder, string $column, mixed $value): string
    {
        return 'MATCH(' . $column . ') AGAINST (' . $builder->createPositionalParameter($value) . ' IN BOOLEAN MODE)';
    }
}
