<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Modelling\Event;

class ProjectorExecutor
{
    public function __construct(
        private MessagingEntrypoint $messagingEntrypoint,
        private array $eventToChannelMapping,
    ) {
    }

    public function project(Event $event): void
    {
        $channel = $this->eventToChannelMapping[$event->getEventName()] ?? null;
        if (!$channel) {
            return;
        }
        $this->messagingEntrypoint->send(
            $event->getPayload(),
            $channel,
        );
    }
}