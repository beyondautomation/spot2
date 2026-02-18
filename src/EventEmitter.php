<?php

declare(strict_types=1);

namespace Spot;

/**
 * EventEmitter — thin wrapper around sabre/event's EmitterTrait.
 *
 * Brings sabre/event into the Spot namespace so that mappers and entities
 * can work with a single, consistent event type.
 *
 * @package Spot
 */
class EventEmitter
{
    use \Sabre\Event\EmitterTrait;
}
