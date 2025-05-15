<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Modelling\Event;

class EcotoneProjectorExecutor implements ProjectorExecutor
{
    /**
     * @param array<string, string> $eventToChannelMapping key is event name, value is channel name
     * @param array<string, bool> $eventToChannelDoesReturnStateMapping key is event name, value is true if the channel returns state
     */
    public function __construct(
        private MessagingEntrypoint $messagingEntrypoint,
        private array $eventToChannelMapping,
        private array $eventToChannelDoesReturnStateMapping,
    ) {
    }

    public function project(Event $event, mixed $userState = null): mixed
    {
        $channel = $this->eventToChannelMapping[$event->getEventName()] ?? null;
        if (!$channel) {
            return $userState;
        }
        $metadata = $event->getMetadata();
        $metadata[ProjectingHeaders::PROJECTION_STATE] = $userState;

        $newUserState = $this->messagingEntrypoint->sendWithHeaders(
            $event->getPayload(),
            $metadata,
            $channel,
        );

        if ($this->eventToChannelDoesReturnStateMapping[$event->getEventName()] ?? false) {
            return $newUserState;
        } else {
            return $userState;
        }
    }
}