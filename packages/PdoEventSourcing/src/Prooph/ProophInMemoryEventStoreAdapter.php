<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Prooph;

use ArrayIterator;
use DateTimeImmutable;
use DateTimeZone;
use Ecotone\EventSourcing\EventStore\FieldType;
use Ecotone\EventSourcing\EventStore\InMemoryEventStore as EcotoneInMemoryEventStore;
use Ecotone\EventSourcing\EventStore\MetadataMatcher as EcotoneMetadataMatcher;
use Ecotone\EventSourcing\EventStore\Operator;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Event;

use function is_array;

use Iterator;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Exception\StreamExistsAlready;
use Prooph\EventStore\Exception\StreamNotFound;
use Prooph\EventStore\Metadata\MetadataMatcher;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamIterator\InMemoryStreamIterator;
use Prooph\EventStore\StreamName;
use Ramsey\Uuid\Uuid;

/**
 * Adapter that wraps Ecotone's InMemoryEventStore to implement Prooph's EventStore interface
 * All write operations are delegated to Ecotone's InMemoryEventStore
 * licence Apache-2.0
 */
final class ProophInMemoryEventStoreAdapter implements EventStore
{
    public function __construct(
        private EcotoneInMemoryEventStore $ecotoneEventStore
    ) {
    }

    public function getEcotoneEventStore(): EcotoneInMemoryEventStore
    {
        return $this->ecotoneEventStore;
    }

    public function create(Stream $stream): void
    {
        $streamName = $stream->streamName()->toString();

        if ($this->ecotoneEventStore->hasStream($streamName)) {
            throw StreamExistsAlready::with($stream->streamName());
        }

        $ecotoneEvents = $this->convertToEcotoneEvents($stream->streamEvents());
        $this->ecotoneEventStore->create($streamName, $ecotoneEvents, $stream->metadata());
    }

    public function appendTo(StreamName $streamName, Iterator $streamEvents): void
    {
        $streamNameString = $streamName->toString();

        if (! $this->ecotoneEventStore->hasStream($streamNameString)) {
            throw StreamNotFound::with($streamName);
        }

        // Convert to array to allow iteration twice
        $streamEventsArray = iterator_to_array($streamEvents);

        $ecotoneEvents = $this->convertToEcotoneEvents(new ArrayIterator($streamEventsArray));
        $this->ecotoneEventStore->appendTo($streamNameString, $ecotoneEvents);
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
                is_array($event->getPayload()) ? $event->getPayload() : [$event->getPayload()],
                $metadata,
                $event->getEventName()
            );
        }
        return $messages;
    }

    private function convertToEcotoneMetadataMatcher(MetadataMatcher $proophMetadataMatcher): EcotoneMetadataMatcher
    {
        $mappedData = [];
        foreach ($proophMetadataMatcher->data() as $item) {
            $mappedData[] = [
                'field' => $item['field'],
                'operator' => $item['operator'] !== null ? Operator::from($item['operator']->getValue()) : null,
                'value' => $item['value'],
                'fieldType' => $item['fieldType'] !== null ? FieldType::from($item['fieldType']->getValue()) : null,
            ];
        }

        return EcotoneMetadataMatcher::create($mappedData);
    }
}
