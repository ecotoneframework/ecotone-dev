<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing;

use Ecotone\EventSourcing\Mapping\EventMapper;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Handler\TypeDefinitionException;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Event;

use function is_array;

/**
 * licence Apache-2.0
 */
final class EventSerializer
{
    public function __construct(
        private ConversionService $conversionService,
        private EventMapper $eventMapper,
    ) {
    }

    public function serialize(object|array $event): Event
    {
        if ($event instanceof Event) {
            $payload = $event->getPayload();
            $metadata = $event->getMetadata();
        } else {
            $payload = $event;
            $metadata = [];
            $event = Event::create($payload);
        }

        return Event::createWithType(
            $this->eventMapper->mapEventToName($event),
            is_array($payload)
                ? $payload
                : $this->conversionService->convert($payload, Type::createFromVariable($payload), MediaType::createApplicationXPHP(), Type::array(), MediaType::createApplicationXPHP()),
            $metadata
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function deserialize(string $eventName, array $payload, array $metadata, bool $deserialize): Event
    {
        $eventType = null;
        try {
            $eventType = Type::create($this->eventMapper->mapNameToEventType($eventName));
        } catch (TypeDefinitionException) {
        }

        return Event::createWithType(
            eventType: $eventType === null ? $eventName : $eventType->toString(),
            event: $deserialize && $eventType !== null
                ? $this->conversionService->convert($payload, Type::array(), MediaType::createApplicationXPHP(), $eventType, MediaType::createApplicationXPHP())
                : $payload,
            metadata: array_merge([MessageHeaders::REVISION => 1], $metadata)
        );
    }
}
