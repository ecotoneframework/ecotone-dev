<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\MessageHeaders;
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
        private string $projectionName, // this is required for event stream emitter so it can create a stream with this name
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

        // Those three headers are required by EventStreamEmitter
        $metadata[ProjectingHeaders::PROJECTION_NAME] = $this->projectionName;
        $metadata[ProjectingHeaders::PROJECTION_IS_REBUILDING] = false;
        $metadata[MessageHeaders::STREAM_BASED_SOURCED] = true; // this one is required for correct header propagation in EventStreamEmitter...

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