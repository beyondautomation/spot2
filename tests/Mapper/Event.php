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
    public function scopes(): array
    {
        return [
            'free' => function (Query $query) {
                return $query->where(['type' => 'free']);
            },
            'active' => function (Query $query) {
                return $query->where(['status' => 1]);
            },
        ];
    }

    /**
     * Just generate a test query so we can ensure this method is getting called
     *
     * @return \Spot\Query
     */
    public function testQuery()
    {
        return $this->where(['title' => 'test']);
    }
}
