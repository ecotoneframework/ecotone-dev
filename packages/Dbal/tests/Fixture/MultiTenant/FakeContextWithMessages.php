<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant;

use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\PollableChannel;
use Interop\Queue\Consumer;
use Interop\Queue\Context;
use Interop\Queue\Destination;
use Interop\Queue\Message;
use Interop\Queue\Producer;
use Interop\Queue\Queue;
use Interop\Queue\SubscriptionConsumer;
use Interop\Queue\Topic;

/**
 * licence Apache-2.0
 */
final class FakeContextWithMessages implements Context, PollableChannel
{
    private QueueChannel $channel;

    public function __construct()
    {
        $this->channel = QueueChannel::create();
    }

    public function send(\Ecotone\Messaging\Message $message): void
    {
        $this->channel->send($message);
    }

    public function receiveWithTimeout(int $timeoutInMilliseconds): ?\Ecotone\Messaging\Message
    {
        return $this->receive();
    }

    public function receive(): ?\Ecotone\Messaging\Message
    {
        return $this->channel->receive();
    }

    public function createMessage(string $body = '', array $properties = [], array $headers = []): Message
    {
        // TODO: Implement createMessage() method.
    }

    public function createTopic(string $topicName): Topic
    {
        // TODO: Implement createTopic() method.
    }

    public function createQueue(string $queueName): Queue
    {
        // TODO: Implement createQueue() method.
    }

    public function createTemporaryQueue(): Queue
    {
        // TODO: Implement createTemporaryQueue() method.
    }

    public function createProducer(): Producer
    {
        // TODO: Implement createProducer() method.
    }

    public function createConsumer(Destination $destination): Consumer
    {
        // TODO: Implement createConsumer() method.
    }

    public function createSubscriptionConsumer(): SubscriptionConsumer
    {
        // TODO: Implement createSubscriptionConsumer() method.
    }

    public function purgeQueue(Queue $queue): void
    {
        // TODO: Implement purgeQueue() method.
    }

    public function close(): void
    {
        // TODO: Implement close() method.
    }
}
