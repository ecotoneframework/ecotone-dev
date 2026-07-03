<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\AsyncPublishing;

use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingRegistry;
use Ecotone\Messaging\Channel\BatchSupportingMessageChannel;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * licence Apache-2.0
 */
final class InMemoryAsyncPublishingChannel implements PollableChannel, BatchSupportingMessageChannel
{
    /** @var Message[] */
    private array $queue = [];

    private ?string $deliveryFailureReason = null;

    public function __construct(
        private string $channelName,
        private AsyncPublishingRegistry $asyncPublishingRegistry,
        private OperationsLog $operationsLog,
    ) {
    }

    public function send(Message $message): void
    {
        $payload = $message->getPayload();

        if ($payload instanceof BatchMessage) {
            $this->operationsLog->log(sprintf('published batch of %d messages to broker', count($payload)));
            foreach ($payload->getEntries() as $entry) {
                $this->queue[] = MessageBuilder::withPayload($entry['payload'])
                    ->setMultipleHeaders($entry['headers'])
                    ->build();
            }
        } else {
            $this->operationsLog->log('published message to broker');
            $this->queue[] = $message;
        }

        $this->asyncPublishingRegistry->register(
            $this->channelName,
            new InMemoryPendingDelivery($message, $this->deliveryFailureReason, $this->operationsLog),
        );
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

    public function failDeliveriesWith(string $failureReason): void
    {
        $this->deliveryFailureReason = $failureReason;
    }
}
