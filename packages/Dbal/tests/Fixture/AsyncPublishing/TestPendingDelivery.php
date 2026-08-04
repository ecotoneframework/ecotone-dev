<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\AsyncPublishing;

use Ecotone\Messaging\Channel\AsyncPublishing\DeliveryResult;
use Ecotone\Messaging\Channel\AsyncPublishing\FailedDelivery;
use Ecotone\Messaging\Channel\AsyncPublishing\PendingDelivery;
use Ecotone\Messaging\Message;

/**
 * licence Apache-2.0
 */
final class TestPendingDelivery implements PendingDelivery
{
    private bool $awaited = false;

    public function __construct(
        private Message $message,
        private string $channelName,
        private ?string $failureReason = null,
    ) {
    }

    public function awaitDelivery(): DeliveryResult
    {
        $this->awaited = true;

        if ($this->failureReason !== null) {
            return DeliveryResult::withFailedDeliveries([
                new FailedDelivery($this->message, $this->failureReason, $this->channelName),
            ]);
        }

        return DeliveryResult::successful();
    }

    public function isAwaited(): bool
    {
        return $this->awaited;
    }
}
