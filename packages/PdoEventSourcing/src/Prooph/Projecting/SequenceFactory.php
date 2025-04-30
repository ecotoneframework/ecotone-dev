<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph\Projecting;

use Ecotone\Modelling\Event;

interface SequenceFactory
{
    public function create(?string $lastPosition): SequenceTracker;

    /**
     * @param list<Event> $events
     */
    public function createPositionFrom(?string $lastPosition, array $events): string;
}