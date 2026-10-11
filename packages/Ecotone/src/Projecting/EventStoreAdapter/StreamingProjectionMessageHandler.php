<?php

namespace Ecotone\Projecting\EventStoreAdapter;

use Ecotone\Api\EventSourcing\Event;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Projecting\ProjectorExecutor;

/**
 * Message handler that consumes from streaming channel and executes projection
 *
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
