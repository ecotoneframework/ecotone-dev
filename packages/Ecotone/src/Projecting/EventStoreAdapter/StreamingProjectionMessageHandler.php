<?php

namespace Ecotone\Projecting\EventStoreAdapter;

use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Message;
use Ecotone\Projecting\ProjectorExecutor;

/**
 * Message handler that consumes from streaming channel and executes projection
 *
 * licence Enterprise
 */
class StreamingProjectionMessageHandler implements DefinedObject
{
    public function __construct(
        private ProjectorExecutor $projectorExecutor,
        private string $projectionName,
    ) {
    }

    public function handle(Message $message): void
    {
        $headers = $message->getHeaders();
        $typeId = $headers->containsKey(MessageHeaders::TYPE_ID)
            ? $headers->get(MessageHeaders::TYPE_ID)
            : get_class($message->getPayload());

        $event = Event::createWithType(
            $typeId,
            $message->getPayload(),
            $headers->headers()
        );

        $this->projectorExecutor->project($event, null);
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [
            // Will be injected by container
        ]);
    }
}
