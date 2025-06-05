<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies;

class LogEvent
{
    public function __construct(public readonly string $name)
    {
    }
}
