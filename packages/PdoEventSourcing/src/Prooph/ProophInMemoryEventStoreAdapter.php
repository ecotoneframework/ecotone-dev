<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph;

use ArrayIterator;
use DateTimeImmutable;
use DateTimeZone;
use Ecotone\EventSourcing\EventStore\InMemoryEventStore as EcotoneInMemoryEventStore;
use Ecotone\EventSourcing\EventStore\MetadataMatcher as EcotoneMetadataMatcher;
use Ecotone\EventSourcing\Prooph\Metadata\MetadataMatcher as ProophMetadataMatcherWrapper;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Event;
use Iterator;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\EventStoreDecorator;
use Prooph\EventStore\Exception\StreamExistsAlready;
use Prooph\EventStore\Exception\StreamNotFound;
use Prooph\EventStore\InMemoryEventStore as ProophInMemoryEventStore;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamIterator\InMemoryStreamIterator;
use Prooph\EventStore\StreamName;
use Ramsey\Uuid\Uuid;

/**
 * Adapter that wraps Ecotone's InMemoryEventStore to implement Prooph's EventStore interface
 * Implements EventStoreDecorator to provide a Prooph InMemoryEventStore for projection compatibility
 * All write operations are delegated to Ecotone's InMemoryEventStore
 * licence Apache-2.0
 */
final class ProophInMemoryEventStoreAdapter implements EventStoreDecorator
{
    private ?ProophInMemoryEventStore $proophEventStore = null;

    public function __construct(
        private EcotoneInMemoryEventStore $ecotoneEventStore
    ) {
    }

    public function getInnerEventStore(): EventStore
    {
        if ($this->proophEventStore === null) {
            $this->proophEventStore = $this->createProophEventStore();
        }

        return $this->proophEventStore;
    }

    private function createProophEventStore(): ProophInMemoryEventStore
    {
        $proophEventStore = new ProophInMemoryEventStore();

        // Get all streams from Ecotone event store and recreate them in Prooph event store
        $streams = $this->ecotoneEventStore->getAllStreams();

        foreach ($streams as $streamName => $streamData) {
            $ecotoneEvents = $streamData['events'];
            $metadata = $streamData['metadata'];

            $proophMessages = $this->convertToProophMessages($ecotoneEvents);
            $stream = new Stream(new StreamName($streamName), new \ArrayIterator($proophMessages), $metadata);
            $proophEventStore->create($stream);
        }

        return $proophEventStore;
    }

    public function create(Stream $stream): void
    {
        $streamName = $stream->streamName()->toString();

        if ($this->ecotoneEventStore->hasStream($streamName)) {
            throw StreamExistsAlready::with($stream->streamName());
        }

        $ecotoneEvents = $this->convertToEcotoneEvents($stream->streamEvents());
        $this->ecotoneEventStore->create($streamName, $ecotoneEvents, $stream->metadata());

        // Also create in Prooph event store if it's been initialized
        if ($this->proophEventStore !== null) {
            $this->proophEventStore->create($stream);
        }
    }

    public function appendTo(StreamName $streamName, Iterator $streamEvents): void
    {
        $streamNameString = $streamName->toString();

        if (! $this->ecotoneEventStore->hasStream($streamNameString)) {
            throw StreamNotFound::with($streamName);
        }

        // Convert to array to allow iteration twice
        $streamEventsArray = iterator_to_array($streamEvents);

        $ecotoneEvents = $this->convertToEcotoneEvents(new \ArrayIterator($streamEventsArray));
        $this->ecotoneEventStore->appendTo($streamNameString, $ecotoneEvents);

        // Also append to Prooph event store if it's been initialized
        if ($this->proophEventStore !== null) {
            $this->proophEventStore->appendTo($streamName, new \ArrayIterator($streamEventsArray));
        }
    }

    public function load(
        StreamName $streamName,
        int $fromNumber = 1,
        ?int $count = null,
        ?MetadataMatcher $metadataMatcher = null
    ): Iterator {
        $streamNameString = $streamName->toString();

        if (! $this->ecotoneEventStore->hasStream($streamNameString)) {
            throw StreamNotFound::with($streamName);
        }

        $ecotoneMetadataMatcher = null;
        if ($metadataMatcher !== null) {
            $ecotoneMetadataMatcher = $this->convertToEcotoneMetadataMatcher($metadataMatcher);
        }

        $ecotoneEvents = $this->ecotoneEventStore->load(
            $streamNameString,
            $fromNumber,
            $count,
            $ecotoneMetadataMatcher,
            false
        );

        $proophMessages = $this->convertToProophMessages($ecotoneEvents);

        return new InMemoryStreamIterator($proophMessages);
    }

    public function loadReverse(
        StreamName $streamName,
        ?int $fromNumber = null,
        ?int $count = null,
        ?MetadataMatcher $metadataMatcher = null
    ): Iterator {
        $streamNameString = $streamName->toString();

        if (! $this->ecotoneEventStore->hasStream($streamNameString)) {
            throw StreamNotFound::with($streamName);
        }

        $ecotoneMetadataMatcher = null;
        if ($metadataMatcher !== null) {
            $ecotoneMetadataMatcher = $this->convertToEcotoneMetadataMatcher($metadataMatcher);
        }

        $ecotoneEvents = $this->ecotoneEventStore->loadReverse(
            $streamNameString,
            $fromNumber,
            $count,
            $ecotoneMetadataMatcher,
            false
        );

        $proophMessages = $this->convertToProophMessages($ecotoneEvents);

        return new InMemoryStreamIterator($proophMessages);
    }

    public function delete(StreamName $streamName): void
    {
        $streamNameString = $streamName->toString();

        if (! $this->ecotoneEventStore->hasStream($streamNameString)) {
            throw StreamNotFound::with($streamName);
        }

        $this->ecotoneEventStore->delete($streamNameString);

        // Also delete from Prooph event store if it's been initialized
        if ($this->proophEventStore !== null) {
            $this->proophEventStore->delete($streamName);
        }
    }

    public function hasStream(StreamName $streamName): bool
    {
        return $this->ecotoneEventStore->hasStream($streamName->toString());
    }

    public function fetchStreamMetadata(StreamName $streamName): array
    {
        // Not supported by Ecotone's InMemoryEventStore yet
        return [];
    }

    public function updateStreamMetadata(StreamName $streamName, array $newMetadata): void
    {
        // Not supported by Ecotone's InMemoryEventStore yet
    }

    public function fetchStreamNames(?string $filter, ?MetadataMatcher $metadataMatcher, int $limit = 20, int $offset = 0): array
    {
        // Not supported by Ecotone's InMemoryEventStore yet
        return [];
    }

    public function fetchStreamNamesRegex(string $filter, ?MetadataMatcher $metadataMatcher, int $limit = 20, int $offset = 0): array
    {
        // Not supported by Ecotone's InMemoryEventStore yet
        return [];
    }

    public function fetchCategoryNames(?string $filter, int $limit = 20, int $offset = 0): array
    {
        // Not supported by Ecotone's InMemoryEventStore yet
        return [];
    }

    public function fetchCategoryNamesRegex(string $filter, int $limit = 20, int $offset = 0): array
    {
        // Not supported by Ecotone's InMemoryEventStore yet
        return [];
    }

    private function convertToEcotoneEvents(Iterator $proophMessages): array
    {
        $events = [];
        foreach ($proophMessages as $message) {
            /** @var ProophMessage $message */
            $events[] = Event::createWithType(
                $message->messageName(),
                $message->payload(),
                array_merge(
                    [
                        MessageHeaders::MESSAGE_ID => $message->uuid()->toString(),
                        MessageHeaders::TIMESTAMP => $message->createdAt()->getTimestamp(),
                    ],
                    $message->metadata()
                )
            );
        }
        return $events;
    }

    private function convertToProophMessages(iterable $ecotoneEvents): array
    {
        $messages = [];
        foreach ($ecotoneEvents as $event) {
            /** @var Event $event */
            $metadata = $event->getMetadata();
            $messages[] = new ProophMessage(
                isset($metadata[MessageHeaders::MESSAGE_ID]) ? Uuid::fromString($metadata[MessageHeaders::MESSAGE_ID]) : Uuid::uuid4(),
                isset($metadata[MessageHeaders::TIMESTAMP]) ? new DateTimeImmutable('@' . $metadata[MessageHeaders::TIMESTAMP], new DateTimeZone('UTC')) : new DateTimeImmutable('now', new DateTimeZone('UTC')),
                \is_array($event->getPayload()) ? $event->getPayload() : [$event->getPayload()],
                $metadata,
                $event->getEventName()
            );
        }
        return $messages;
    }

    private function convertToEcotoneMetadataMatcher(MetadataMatcher $proophMetadataMatcher): EcotoneMetadataMatcher
    {
        // Create Ecotone MetadataMatcher from Prooph's data
        return EcotoneMetadataMatcher::create($proophMetadataMatcher->data());
    }
}

