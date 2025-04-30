<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting;

use Ecotone\Modelling\Event;
use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;

class InMemoryStreamSource implements StreamSource
{
    /**
     * @param Event[] $events
     */
    public function __construct(
        private array $events = []
    ) {
    }

    public function append(Event ...$events): void
    {
        foreach ($events as $event) {
            $this->events[] = $event;
        }
    }

    public function load(?string $lastPosition, int $count, ?string $partitionKey = null): StreamPage
    {
        $from = $lastPosition !== null ? (int) $lastPosition : 0;

        $events = array_slice($this->events, $from, $count);
        $to = $from + count($events);

        return new StreamPage($events, (string) $to);
    }
}