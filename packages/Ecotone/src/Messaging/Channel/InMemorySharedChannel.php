<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel;

use Ecotone\Messaging\Channel\PollableChannel\InMemory\InMemorySharedAcknowledgeCallback;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Messaging\Support\MessageBuilder;

/**
 * Shared channel that allows multiple consumers to read messages independently
 * Each consumer tracks its own position using ConsumerPositionTracker
 *
 * licence Apache-2.0
 */
final class InMemorySharedChannel implements PollableChannel, DefinedObject
{
    /**
     * @var Message[] All messages stored in the channel (indexed by position)
     */
    private array $messages = [];

    /**
     * @var int Auto-incrementing position counter
     */
    private int $nextPosition = 0;

    public function __construct(
        private string $name,
        private ConsumerPositionTracker $positionTracker,
        private FinalFailureStrategy $finalFailureStrategy = FinalFailureStrategy::RESEND,
        private bool $isAutoAcked = true,
    ) {
    }

    public static function create(
        string $name,
        ConsumerPositionTracker $positionTracker,
        FinalFailureStrategy $finalFailureStrategy = FinalFailureStrategy::RESEND,
        bool $isAutoAcked = true,
    ): self {
        return new self($name, $positionTracker, $finalFailureStrategy, $isAutoAcked);
    }

    /**
     * Send message to the channel (appends to the end)
     */
    public function send(Message $message): void
    {
        $this->messages[$this->nextPosition] = $message;
        $this->nextPosition++;
    }

    /**
     * Not supported for shared channels - use receiveWithTimeout instead
     */
    public function receive(): ?Message
    {
        throw new InvalidArgumentException('receive() is not supported for shared channels. Use receiveWithTimeout() instead.');
    }

    /**
     * Receive message for a specific consumer identified by endpointId in PollingMetadata
     */
    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        $consumerId = $pollingMetadata->getEndpointId();

        // Load consumer's current position
        $positionStr = $this->positionTracker->loadPosition($consumerId);
        $position = $positionStr !== null ? (int)$positionStr : 0;

        // Check if there's a message at this position
        if (!isset($this->messages[$position])) {
            return null;
        }

        $message = $this->messages[$position];

        // Add acknowledgement callback to the message
        $callback = new InMemorySharedAcknowledgeCallback(
            positionTracker: $this->positionTracker,
            endpointId: $consumerId,
            currentPosition: $position,
            failureStrategy: $this->finalFailureStrategy,
            isAutoAcked: $this->isAutoAcked,
        );

        return MessageBuilder::fromMessage($message)
            ->setHeader(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, InMemorySharedAcknowledgeCallback::ECOTONE_IN_MEMORY_SHARED_ACK)
            ->setHeader(InMemorySharedAcknowledgeCallback::ECOTONE_IN_MEMORY_SHARED_ACK, $callback)
            ->build();
    }

    public function onConsumerStop(): void
    {
        // No cleanup needed for shared channels
    }

    public function getPositionTracker(): ConsumerPositionTracker
    {
        return $this->positionTracker;
    }

    public function __toString()
    {
        return 'in memory shared channel: ' . $this->name;
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [
            $this->name,
            $this->positionTracker,
            $this->finalFailureStrategy,
            $this->isAutoAcked,
        ]);
    }
}

