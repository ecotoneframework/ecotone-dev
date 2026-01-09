<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

use Ecotone\Modelling\Event;

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
