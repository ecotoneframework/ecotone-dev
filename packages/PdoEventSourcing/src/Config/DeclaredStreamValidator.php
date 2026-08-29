<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Config;

use Ecotone\EventSourcing\StreamTableRegistry;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Messaging\Message;

use function implode;

/**
 * licence Apache-2.0
 */
final class DeclaredStreamValidator implements MessageProcessor, DefinedObject
{
    public function __construct(private StreamTableRegistry $streamTableRegistry)
    {
    }

    public function process(Message $message): Message
    {
        $streamName = $message->getHeaders()->get('ecotone.eventSourcing.eventStore.streamName');

        if (! $this->streamTableRegistry->isDeclared($streamName)) {
            throw ConfigurationException::create(
                "Event stream `{$streamName}` is not declared. Declare it with #[Stream('{$streamName}')] on the class that owns it. Declared streams: "
                . implode(', ', $this->streamTableRegistry->declaredStreamNames())
            );
        }

        return $message;
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [new Reference(StreamTableRegistry::class)]);
    }
}
