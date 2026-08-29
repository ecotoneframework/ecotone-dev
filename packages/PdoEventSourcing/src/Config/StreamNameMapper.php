<?php

namespace Ecotone\EventSourcing\Config;

use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Projecting\ProjectingHeaders;

/**
 * licence Apache-2.0
 */
final class StreamNameMapper implements MessageProcessor, DefinedObject
{
    public function process(Message $message): Message
    {
        return MessageBuilder::fromMessage($message)
                ->setHeader('ecotone.eventSourcing.eventStore.streamName', 'projection_' . $message->getHeaders()->get(ProjectingHeaders::PROJECTION_NAME))
                ->build();
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class);
    }
}
