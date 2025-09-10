<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\Event;

class EcotoneProjectorExecutor implements ProjectorExecutor
{
    public function __construct(
        private MessagingEntrypoint $messagingEntrypoint,
        private string $projectionName, // this is required for event stream emitter so it can create a stream with this name
        private string $projectChannel,
        private ?string $initChannel = null,
        private ?string $deleteChannel = null,
    ) {
    }

    public function project(Event $event, mixed $userState = null): mixed
    {
        $metadata = $event->getMetadata();
        $metadata[ProjectingHeaders::PROJECTION_STATE] = $userState ?? null;
        $metadata[ProjectingHeaders::PROJECTION_EVENT_NAME] = $event->getEventName();

        // Those three headers are required by EventStreamEmitter
        $metadata[ProjectingHeaders::PROJECTION_NAME] = $this->projectionName;
        $metadata[ProjectingHeaders::PROJECTION_IS_REBUILDING] = false;
        $metadata[MessageHeaders::STREAM_BASED_SOURCED] = true; // this one is required for correct header propagation in EventStreamEmitter...
        $metadata[MessagingEntrypoint::ENTRYPOINT] = $this->projectChannel;

        // constructing the message here is way faster than doing it in the gateway (avoids conversion overhead I guess)
        $newUserState = $this->messagingEntrypoint->sendMessage(
            MessageBuilder::withPayload($event->getPayload())
                ->setMultipleHeaders($metadata)
                ->build()
        );

        if (!\is_null($newUserState)) {
            return $newUserState;
        } else {
            return $userState;
        }
    }

    public function init(): void
    {
        if ($this->initChannel) {
            $this->messagingEntrypoint->send([], $this->initChannel);
        }
    }

    public function delete(): void
    {
        if ($this->deleteChannel) {
            $this->messagingEntrypoint->send([], $this->deleteChannel);
        }
    }
}