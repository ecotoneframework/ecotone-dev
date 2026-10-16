<?php

namespace Ecotone\EventSourcing\Config;

use Ecotone\Api\Messaging\Message;
use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\MessageProcessor;
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
    public function __construct(
        private MessageBuilder $messageBuilder,
        private array $projectionStreamMapping = [],
    ) {
    }

    public function process(Message $message): Message
    {
        $headers = $message->getHeaders();
        $streamName = $headers->containsKey(ProjectingHeaders::PROJECTION_NAME)
            ? $this->projectionStreamMapping[$headers->get(ProjectingHeaders::PROJECTION_NAME)] ?? StreamTableRegistry::DEFAULT_STREAM
            : StreamTableRegistry::DEFAULT_STREAM;

        return $this->messageBuilder->fromMessage($message)
                ->setHeader('ecotone.eventSourcing.eventStore.streamName', $streamName)
                ->build();
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [Reference::to(MessageBuilder::class), $this->projectionStreamMapping]);
    }
}
