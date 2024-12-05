<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore;

/**
 * @template EventData
 */
readonly class Event
{
    /**
     * @param EventData $data
     */
    public function __construct(
        public string $type,
        public mixed $data
    ) {
    }
}