<?php

namespace Ecotone\EventSourcing\Prooph;

use ArrayIterator;
use DateTimeImmutable;
use DateTimeZone;
use Ecotone\EventSourcing\EventStore;
use Ecotone\EventSourcing\EventStore\MetadataMatcher as EcotoneMetadataMatcher;
use Ecotone\EventSourcing\Prooph\Metadata\MetadataMatcher as ProophMetadataMatcherWrapper;
use Ecotone\EventSourcing\ProophEventMapper;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Handler\TypeDefinitionException;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Event;
use Iterator;
use Prooph\EventStore\EventStore as ProophEventStore;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamName;
use Ramsey\Uuid\Uuid;

/**
 * licence Apache-2.0
 */
class EcotoneEventStoreProophWrapper implements EventStore
{
    private LazyProophEventStore $eventStore;
    private ConversionService $conversionService;
    private ProophEventMapper $eventMapper;

    private function __construct(LazyProophEventStore $eventStore, ConversionService $conversionService, ProophEventMapper $eventMapper)
    {
        $this->eventStore = $eventStore;
        $this->conversionService = $conversionService;
        $this->eventMapper = $eventMapper;
    }

    public static function prepare(LazyProophEventStore $eventStore, ConversionService $conversionService, ProophEventMapper $eventMapper): static
    {
        return new self($eventStore, $conversionService, $eventMapper);
    }

    /**
     * @inheritDoc
     */
    public function create(string $streamName, array $streamEvents = [], array $streamMetadata = []): void
    {
        $this->eventStore->create(new Stream(new StreamName($streamName), $this->convertProophEvents($streamEvents), $streamMetadata));
    }

    /**
     * @param Event[]|object[]|array[] $events
     */
    private function convertProophEvents(array $events): ArrayIterator
    {
        $proophEvents = [];
        foreach ($events as $eventToConvert) {
            if ($eventToConvert instanceof ProophMessage) {
                $proophEvents[] = $eventToConvert;

                continue;
            }

            if ($eventToConvert instanceof Event) {
                $payload = $eventToConvert->getPayload();
                $metadata = $eventToConvert->getMetadata();
            } else {
                $payload = $eventToConvert;
                $metadata = [];
                $eventToConvert = Event::create($payload);
            }

            $proophEvents[] = new ProophMessage(
                array_key_exists(MessageHeaders::MESSAGE_ID, $metadata) ? Uuid::fromString($metadata[MessageHeaders::MESSAGE_ID]) : Uuid::uuid4(),
                array_key_exists(MessageHeaders::TIMESTAMP, $metadata) ? new DateTimeImmutable('@' . $metadata[MessageHeaders::TIMESTAMP], new DateTimeZone('UTC')) : new DateTimeImmutable('now', new DateTimeZone('UTC')),
                is_array($payload) ? $payload : $this->conversionService->convert($payload, Type::createFromVariable($payload), MediaType::createApplicationXPHP(), Type::array(), MediaType::createApplicationXPHP()),
                $metadata,
                $this->eventMapper->mapEventToName($eventToConvert)
            );
        }

        return new ArrayIterator($proophEvents);
    }

    public function getWrappedEventStore(): LazyProophEventStore
    {
        return $this->eventStore;
    }

    public function getWrappedProophEventStore(): ProophEventStore
    {
        return $this->getWrappedEventStore()->getEventStore();
    }

    public function appendTo(string $streamName, array $streamEvents): void
    {
        $this->eventStore->appendTo(new StreamName($streamName), $this->convertProophEvents($streamEvents));
    }

    public function delete(string $streamName): void
    {
        $this->eventStore->delete(new StreamName($streamName));
    }

    public function hasStream(string $streamName): bool
    {
        return $this->eventStore->hasStream(new StreamName($streamName));
    }

    public function load(string $streamName, int $fromNumber = 1, ?int $count = null, ?EcotoneMetadataMatcher $metadataMatcher = null, bool $deserialize = true): array
    {
        $proophMetadataMatcher = null;
        if ($metadataMatcher !== null) {
            $proophMetadataMatcher = $this->convertToProophMetadataMatcher($metadataMatcher);
        }

        $streamEvents = $this->eventStore->load(new StreamName($streamName), $fromNumber, $count, $proophMetadataMatcher);
        if (! $streamEvents->valid()) {
            $streamEvents = new ArrayIterator([]);
        }

        return $this->convertToEcotoneEvents(
            $streamEvents,
            $deserialize
        );
    }

    private function convertToProophMetadataMatcher(EcotoneMetadataMatcher $ecotoneMetadataMatcher): \Prooph\EventStore\Metadata\MetadataMatcher
    {
        $wrapper = ProophMetadataMatcherWrapper::createFromEcotone($ecotoneMetadataMatcher);
        return $wrapper->build();
    }

    /**
     * @return Event[]
     */
    private function convertToEcotoneEvents(Iterator $streamEvents, bool $deserialize): array
    {
        $events = [];
        $sourcePHPType = Type::array();
        $PHPMediaType = MediaType::createApplicationXPHP();
        /** @var ProophMessage $event */
        while ($event = $streamEvents->current()) {
            try {
                $eventName = Type::create($this->eventMapper->mapNameToEventType($event->messageName()));
            } catch (TypeDefinitionException $e) {
                // Fallback to using the message name as is if we find an unknown event type (deleted class etc.)
                $eventName = $event->messageName();
            }
            $events[] = Event::createWithType(
                $eventName,
                $deserialize ? $this->conversionService->convert($event->payload(), $sourcePHPType, $PHPMediaType, $eventName, $PHPMediaType) : $event->payload(),
                array_merge(
                    [
                        MessageHeaders::REVISION => 1,
                    ],
                    $event->metadata()
                )
            );

            $streamEvents->next();
        }

        return $events;
    }
}
