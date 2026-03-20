<?php

declare(strict_types=1);

namespace SpotTest\Mapper;

use Spot\Mapper;
use Spot\Query;

class Event extends Mapper
{
    /**
     * Custom scopes applied to Spot\Query
     */
    #[\Override]
    public function scopes(): array
    {
        return [
            'free' => fn (Query $query): \Spot\Query => $query->where(['type' => 'free']),
            'active' => fn (Query $query): \Spot\Query => $query->where(['status' => 1]),
        ];
    }

    /**
     * Just generate a test query so we can ensure this method is getting called
     */
    public function testQuery(): \Spot\Query
    {
        return $this->where(['title' => 'test']);
    }
}
