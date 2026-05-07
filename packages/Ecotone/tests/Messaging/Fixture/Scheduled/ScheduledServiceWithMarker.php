<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Scheduled;

use Ecotone\Messaging\Attribute\Scheduled;
use Ecotone\Messaging\NullableMessageChannel;

/**
 * licence Apache-2.0
 */
final class ScheduledServiceWithMarker
{
    #[Scheduled(requestChannelName: NullableMessageChannel::CHANNEL_NAME, endpointId: 'scheduledWithMarker')]
    #[ScheduledMarkerAttribute('marked')]
    public function poll(): ?string
    {
        return 'payload';
    }
}
