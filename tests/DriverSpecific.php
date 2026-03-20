<?php

declare(strict_types=1);

namespace SpotTest;

/**
 * @package Spot
 */
class DriverSpecific
{
    public static function getWeekFunction($mapper, $field = null): string|false
    {
        if ($mapper->connectionIs('mysql')) {
            return 'WEEK(' . $field . ')';
        }

        if ($mapper->connectionIs('pgsql')) {
            return 'EXTRACT(WEEK FROM TIMESTAMP ' . $field . ')';
        }

        if ($mapper->connectionIs('sqlite')) {
            return "STRFTIME('%W', " . $field . ')';
        }

        return false;
    }
}
