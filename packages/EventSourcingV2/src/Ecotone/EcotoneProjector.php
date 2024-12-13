<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcingV2\Ecotone;

use Ecotone\EventSourcingV2\EventStore\PersistedEvent;
use Ecotone\EventSourcingV2\EventStore\Projection\ProjectorWithSetup;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;

class EcotoneProjector implements ProjectorWithSetup
{
    /**
     * @param array<string, string> $eventToChannelMapping
     */
    public function __construct(
        private MessagingEntrypoint $messagingEntrypoint,
        private array $eventToChannelMapping,
        private ?string $setupChannel = null,
        private ?string $tearDownChannel = null,
    ) {
    }

    public function project(PersistedEvent $event): void
    {
        $route = $this->eventToChannelMapping[$event->event->type] ?? null;
        if ($route === null) {
            return;
        }
        $this->messagingEntrypoint->send(
            $event->event->payload,
            $route,
        );
    }

    public function setUp(): void
    {
        if ($this->setupChannel === null) {
            return;
        }
        $this->messagingEntrypoint->send(
            null,
            $this->setupChannel,
        );
    }

    public function tearDown(): void
    {
        if ($this->tearDownChannel === null) {
            return;
        }
        $this->messagingEntrypoint->send(
            null,
            $this->tearDownChannel,
        );
    }
}