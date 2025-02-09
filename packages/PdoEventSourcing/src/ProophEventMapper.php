<?php

namespace Ecotone\EventSourcing;

use Ecotone\EventSourcing\Mapping\EventMapper;
use Ecotone\EventSourcing\Prooph\ProophMessage;
use Ecotone\Modelling\Event;
use Prooph\Common\Messaging\Message;
use Prooph\Common\Messaging\MessageFactory;
use Ramsey\Uuid\Uuid;

/**
 * licence Apache-2.0
 */
class ProophEventMapper implements MessageFactory
{
    public function __construct(private EventMapper $eventMapper)
    {

    }

    public function createMessageFromArray(string $messageName, array $messageData): Message
    {
        $eventType = $messageName;

        return new ProophMessage(
            Uuid::fromString($messageData['uuid']),
            $messageData['created_at'],
            $messageData['payload'],
            $messageData['metadata'],
            $eventType
        );
    }

    public function mapNameToEventType(string $name): string
    {
        return $this->eventMapper->mapNameToEventType($name);
    }

    public function mapEventToName(Event $event): string
    {
        return $this->eventMapper->mapEventToName($event);
    }
}
