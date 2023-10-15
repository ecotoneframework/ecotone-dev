<?php

namespace Ecotone\EventSourcing\Config;

use Ecotone\EventSourcing\Config\InboundChannelAdapter\ProjectionEventHandler;
use Ecotone\EventSourcing\Prooph\LazyProophProjectionManager;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;

final class StreamNameMapper implements DefinedObject
{
    public function map(Message $message): Message
    {
        return MessageBuilder::fromMessage($message)
                ->setHeader('ecotone.eventSourcing.eventStore.streamName', LazyProophProjectionManager::getProjectionStreamName(
                    $message->getHeaders()->get(ProjectionEventHandler::PROJECTION_NAME)
                ))->build();
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class);
    }
}
