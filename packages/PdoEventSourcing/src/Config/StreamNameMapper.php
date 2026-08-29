<?php

namespace Ecotone\EventSourcing\Config;

use Ecotone\EventSourcing\StreamTableRegistry;
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
    /**
     * @param array<string, string> $projectionStreamMapping
     */
    public function __construct(private array $projectionStreamMapping = [])
    {
    }

    public function process(Message $message): Message
    {
        $projectionName = $message->getHeaders()->get(ProjectingHeaders::PROJECTION_NAME);

        return MessageBuilder::fromMessage($message)
                ->setHeader(
                    'ecotone.eventSourcing.eventStore.streamName',
                    $this->projectionStreamMapping[$projectionName] ?? StreamTableRegistry::DEFAULT_STREAM
                )
                ->build();
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->projectionStreamMapping]);
    }
}
