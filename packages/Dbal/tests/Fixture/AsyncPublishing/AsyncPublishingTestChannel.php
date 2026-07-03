<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\AsyncPublishing;

use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingFailedException;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingRegistry;
use Ecotone\Messaging\Channel\BatchSupportingMessageChannel;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Apache-2.0
 */
final class AsyncPublishingTestChannel implements PollableChannel, BatchSupportingMessageChannel
{
    /** @var Message[] */
    private array $queue = [];

    public function __construct(
        private string $channelName,
        private AsyncPublishingRegistry $asyncPublishingRegistry,
        private ?string $deliveryFailureReason = null,
    ) {
    }

    public function send(Message $message): void
    {
        $payload = $message->getPayload();

        if ($payload instanceof BatchMessage) {
            foreach ($payload->getEntries() as $entry) {
                $this->queue[] = MessageBuilder::withPayload($entry['payload'])
                    ->setMultipleHeaders($entry['headers'])
                    ->build();
            }
        } else {
            $this->queue[] = $message;
        }

        $pendingDelivery = new TestPendingDelivery($message, $this->channelName, $this->deliveryFailureReason);

        if (! $this->asyncPublishingRegistry->isScopeActive()) {
            $deliveryResult = $pendingDelivery->awaitDelivery();
            if (! $deliveryResult->isSuccessful()) {
                throw AsyncPublishingFailedException::withFailedDeliveries($deliveryResult->getFailedDeliveries());
            }

            return;
        }

        $this->asyncPublishingRegistry->register($this->channelName, $pendingDelivery);
    }

    public function receive(): ?Message
    {
        return array_shift($this->queue) ?: null;
    }

    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        return $this->receive();
    }

    public function onConsumerStop(): void
    {
    }

    public function supportsBatchMessages(): bool
    {
        return true;
    }
}
