<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Config;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Projecting\ProjectingHeaders;

/**
 * licence Apache-2.0
 */
final class RunningProjectionValidator implements MessageProcessor, DefinedObject
{
    public function process(Message $message): Message
    {
        if (! $message->getHeaders()->containsKey(ProjectingHeaders::PROJECTION_LIVE)) {
            throw ConfigurationException::create(
                'EventStreamEmitter::emit() was called where no projection is handling an event. '
                . 'emit() links events to the stream of the projection handling the current event, and there is none on this path, so there is no stream to link them to. '
                . "Call emit() from a #[Projection]'s event handler or its #[ProjectionFlush] method, or, to write events from any other handler, "
                . 'call EventStreamEmitter::linkTo($streamName, $events) and declare that stream with #[Stream($streamName)] on the class that owns it.'
            );
        }

        return $message;
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class);
    }
}
