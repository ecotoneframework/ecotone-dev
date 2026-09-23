<?php

declare(strict_types=1);

namespace Ecotone\Api\EventSourcing;

use Ecotone\Modelling\Event;

/**
 * licence Enterprise
 */
final class LoadedEvents
{
    /**
     * @param Event[] $events
     */
    public function __construct(
        public readonly array $events,
        public readonly AppendCondition $appendCondition,
    ) {
    }
}
