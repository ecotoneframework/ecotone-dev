<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\InMemory;

use function count;

use Ecotone\EventSourcing\EventStore\FieldType;
use Ecotone\EventSourcing\EventStore\InMemoryEventStore;
use Ecotone\EventSourcing\EventStore\MetadataMatcher;
use Ecotone\EventSourcing\EventStore\Operator;
use Ecotone\Projecting\StreamPage;
use Ecotone\Projecting\StreamSource;

use function in_array;
use function is_array;

use ReflectionProperty;

class InMemoryEventStoreStreamSource implements StreamSource
{
    /**
     * @param array<string>|null $projectionNames Projection names this source handles, null means all projections
     * @param array<string> $eventNames Event names to filter by, empty array means no filtering
     */
    public function __construct(
        private InMemoryEventStore $eventStore,
        private ?array $projectionNames = null,
        private ?string $streamName = null,
        private ?string $partitionHeader = null,
        private array $eventNames = [],
    ) {
    }

    public function canHandle(string $projectionName): bool
    {
        return $this->projectionNames === null || in_array($projectionName, $this->projectionNames, true);
    }

    public function load(string $projectionName, ?string $lastPosition, int $count, ?string $partitionKey, string $streamName): StreamPage
    {
        $from = $lastPosition !== null ? (int) $lastPosition : 0;

        $streams = $this->getStreamsToRead($streamName);

        $allEvents = [];
        foreach ($streams as $stream) {
            if (! $this->eventStore->hasStream($stream)) {
                continue;
            }

            $metadataMatcher = new MetadataMatcher();
            if ($partitionKey !== null && $this->partitionHeader !== null) {
                $metadataMatcher = $metadataMatcher
                    ->withMetadataMatch($this->partitionHeader, Operator::EQUALS, $partitionKey);
            }

            if ($this->eventNames !== []) {
                $metadataMatcher = $metadataMatcher
                    ->withMetadataMatch('event_name', Operator::IN, $this->eventNames, FieldType::MESSAGE_PROPERTY);
            }

            $events = $this->eventStore->load($stream, 1, null, $metadataMatcher);
            $allEvents = array_merge($allEvents, is_array($events) ? $events : iterator_to_array($events));
        }

        $events = array_slice($allEvents, $from, $count);
        $to = $from + count($events);

        return new StreamPage($events, (string) $to);
    }

    /**
     * @return array<string>
     */
    private function getStreamsToRead(string $streamName): array
    {
        if ($streamName !== '') {
            return [$streamName];
        }

        if ($this->streamName !== null) {
            return [$this->streamName];
        }

        $reflection = new ReflectionProperty($this->eventStore, 'streams');
        $allStreams = array_keys($reflection->getValue($this->eventStore));

        return array_filter($allStreams, fn ($stream) => ! str_starts_with($stream, '$'));
    }
}
