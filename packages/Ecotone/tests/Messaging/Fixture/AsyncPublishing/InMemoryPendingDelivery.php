<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\AsyncPublishing;

use Ecotone\Messaging\Channel\AsyncPublishing\DeliveryResult;
use Ecotone\Messaging\Channel\AsyncPublishing\FailedDelivery;
use Ecotone\Messaging\Channel\AsyncPublishing\PendingDelivery;
use Ecotone\Messaging\Message;

/**
 * licence Apache-2.0
 */
final class InMemoryPendingDelivery implements PendingDelivery
{
    private int $awaitCalls = 0;

    public function __construct(
        private Message $message,
        private ?string $failureReason = null,
        private ?OperationsLog $operationsLog = null,
    ) {
    }

    public function awaitDelivery(): DeliveryResult
    {
        $this->awaitCalls++;
        $this->operationsLog?->log('delivery confirmations awaited');

        if ($this->failureReason !== null) {
            return DeliveryResult::withFailedDeliveries([
                new FailedDelivery($this->message, $this->failureReason),
            ]);
        }

        return DeliveryResult::successful();
    }

    public function isAwaited(): bool
    {
        return $this->awaitCalls > 0;
    }

    public function awaitCalls(): int
    {
        return $this->awaitCalls;
    }
}
