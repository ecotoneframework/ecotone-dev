<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Modelling\Event;
use Ecotone\Projecting\Config\ProjectionBuilder\ProjectionEventHandlerConfiguration;

class EcotoneProjectorExecutor implements ProjectorExecutor
{
    /**
     * @param array<string, ProjectionEventHandlerConfiguration> $projectionEventHandlers key is event name
     */
    public function __construct(
        private MessagingEntrypoint $messagingEntrypoint,
        private array $projectionEventHandlers,
    ) {
    }

    public function project(Event $event, mixed $userState = null): mixed
    {
        $projectionEventHandler = $this->projectionEventHandlers[$event->getEventName()] ?? null;
        if (!$projectionEventHandler) {
            return $userState;
        }
        $metadata = $event->getMetadata();
        $metadata[ProjectingHeaders::PROJECTION_STATE] = $userState;

        $newUserState = $this->messagingEntrypoint->sendWithHeaders(
            $event->getPayload(),
            $metadata,
            $projectionEventHandler->channelName,
        );

        if ($projectionEventHandler->doesItReturnsUserState) {
            return $newUserState;
        } else {
            return $userState;
        }
    }
}