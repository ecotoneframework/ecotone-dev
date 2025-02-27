<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

class StreamPage
{
    public function __construct(
        public readonly array $events,
        public readonly string $lastPosition,
    ) {
    }
}