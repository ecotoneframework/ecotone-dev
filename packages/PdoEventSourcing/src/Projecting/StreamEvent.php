<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

use Ecotone\Api\EventSourcing\Event;

class StreamEvent extends Event
{
    public function __construct(
        string $eventName,
        array|object $payload,
        array $metadata,
        public readonly int $no,
        public readonly int $timestamp,
    ) {
        parent::__construct($eventName, $payload, $metadata);
    }
}
