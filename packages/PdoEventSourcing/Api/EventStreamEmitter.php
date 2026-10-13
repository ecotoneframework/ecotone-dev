<?php

namespace Ecotone\Api\EventSourcing;

/**
 * licence Apache-2.0
 *
 * @link https://docs.ecotone.tech/modelling/event-sourcing/setting-up-projections/emitting-events
 */
interface EventStreamEmitter
{
    /**
     * @param Event[]|object[]|array[] $streamEvents
     */
    public function linkTo(string $streamName, array $streamEvents): void;

    /**
     * @param Event[]|object[]|array[] $streamEvents
     */
    public function emit(array $streamEvents): void;
}
