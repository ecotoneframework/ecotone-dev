<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\AsyncPublishing;

use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Messaging\BatchMessage;
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

    private ?string $failingPayloadFragment = null;

    private bool $registersPendingDeliveries = true;

    public function handle(Message $message, #[Reference] AsyncPublishingRegistry $asyncPublishingRegistry): void
    {
        $this->sentMessages[] = $message;

        if (! $this->registersPendingDeliveries) {
            return;
        }

        $pendingDelivery = new InMemoryPendingDelivery($message, $this->resolveFailureReason($message));
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

    public function failDeliveriesContaining(string $payloadFragment, string $failureReason): void
    {
        $this->failingPayloadFragment = $payloadFragment;
        $this->deliveryFailureReason = $failureReason;
    }

    private function resolveFailureReason(Message $message): ?string
    {
        if ($this->failingPayloadFragment === null) {
            return $this->deliveryFailureReason;
        }

        $payload = $message->getPayload();
        $payloadsToInspect = $payload instanceof BatchMessage
            ? array_column($payload->getEntries(), 'payload')
            : [$payload];

        foreach ($payloadsToInspect as $payloadToInspect) {
            if (is_string($payloadToInspect) && str_contains($payloadToInspect, $this->failingPayloadFragment)) {
                return $this->deliveryFailureReason;
            }
        }

        return null;
    }

    public function actAsSynchronousPublisher(): void
    {
        $this->registersPendingDeliveries = false;
    }
}
