<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

class InMemoryStreamSource implements StreamSource
{
    public function __construct(
        private array $streams = []
    ) {
    }

    public function append(string $streamName, array $events): void
    {
        if (!isset($this->streams[$streamName])) {
            $this->streams[$streamName] = [];
        }
        $this->streams[$streamName] = [...$this->streams[$streamName], ...$events];
    }

    public function load(string $streamName, ?string $lastPosition, int $count): StreamPage
    {
        $events = $this->streams[$streamName] ?? [];
        $from = $lastPosition !== null ? (int) $lastPosition : 0;

        $events = array_slice($events, $from, $count);
        $to = $from + count($events);

        return new StreamPage($events, (string) $to);
    }
}