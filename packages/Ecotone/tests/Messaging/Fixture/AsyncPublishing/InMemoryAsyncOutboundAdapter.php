<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\AsyncPublishing;

use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingRegistry;
use Ecotone\Messaging\Message;

/**
 * licence Apache-2.0
 */
final class InMemoryAsyncOutboundAdapter
{
    /** @var Message[] */
    private array $sentMessages = [];

    /** @var InMemoryPendingDelivery[] */
    private array $pendingDeliveries = [];

    private ?string $deliveryFailureReason = null;

    private bool $registersPendingDeliveries = true;

    public function handle(Message $message, #[Reference] AsyncPublishingRegistry $asyncPublishingRegistry): void
    {
        $this->sentMessages[] = $message;

        if (! $this->registersPendingDeliveries) {
            return;
        }

        $pendingDelivery = new InMemoryPendingDelivery($message, $this->deliveryFailureReason);
        $this->pendingDeliveries[] = $pendingDelivery;
        $asyncPublishingRegistry->register(InMemoryAsyncPublisherModule::PUBLISHER_REFERENCE, $pendingDelivery);
    }

    /**
     * @return Message[]
     */
    public function getSentMessages(): array
    {
        return $this->sentMessages;
    }

    /**
     * @return mixed[]
     */
    public function getSentPayloads(): array
    {
        return array_map(fn (Message $message) => $message->getPayload(), $this->sentMessages);
    }

    public function awaitedDeliveriesCount(): int
    {
        return count(array_filter($this->pendingDeliveries, fn (InMemoryPendingDelivery $pendingDelivery) => $pendingDelivery->isAwaited()));
    }

    public function totalAwaitCalls(): int
    {
        return array_sum(array_map(fn (InMemoryPendingDelivery $pendingDelivery) => $pendingDelivery->awaitCalls(), $this->pendingDeliveries));
    }

    public function failDeliveriesWith(string $failureReason): void
    {
        $this->deliveryFailureReason = $failureReason;
    }

    public function actAsSynchronousPublisher(): void
    {
        $this->registersPendingDeliveries = false;
    }
}
