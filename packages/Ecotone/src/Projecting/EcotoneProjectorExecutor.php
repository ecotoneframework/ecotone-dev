<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Event;

class EcotoneProjectorExecutor implements ProjectorExecutor
{
    public function __construct(
        private MessagingEntrypoint $messagingEntrypoint,
        private string $channelName,
        private string $projectionName, // this is required for event stream emitter so it can create a stream with this name
    ) {
    }

    public function project(Event $event, mixed $userState = null): mixed
    {
        $metadata = $event->getMetadata();
        $metadata[ProjectingHeaders::PROJECTION_STATE] = $userState ?? [];

        // Those three headers are required by EventStreamEmitter
        $metadata[ProjectingHeaders::PROJECTION_NAME] = $this->projectionName;
        $metadata[ProjectingHeaders::PROJECTION_IS_REBUILDING] = false;
        $metadata[MessageHeaders::STREAM_BASED_SOURCED] = true; // this one is required for correct header propagation in EventStreamEmitter...

        $newUserState = $this->messagingEntrypoint->sendWithHeaders(
            $event->getPayload(),
            $metadata,
            $this->channelName,
        );

        if (!\is_null($newUserState)) {
            return $newUserState;
        } else {
            return $userState;
        }
    }
}