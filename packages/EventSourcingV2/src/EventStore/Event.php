<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore;

/**
 * @template EventData
 */
class Event
{
    /**
     * @param EventData $payload
     */
    public function __construct(
        public readonly string $type,
        public readonly mixed $payload,
        public readonly array $metadata = [],
    ) {
    }
}