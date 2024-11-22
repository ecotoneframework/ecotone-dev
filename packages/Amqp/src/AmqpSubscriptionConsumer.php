<?php

namespace Ecotone\Amqp;

use Enqueue\AmqpExt\AmqpConsumer;
use Enqueue\AmqpExt\AmqpContext;
use Interop\Queue\Consumer;

/**
 * licence Apache-2.0
 */
class AmqpSubscriptionConsumer implements \Interop\Amqp\AmqpSubscriptionConsumer
{
    private null|array $subscriber = null;

    public function __construct(private readonly AmqpContext $context)
    {
    }

    /**
     * @throws \AMQPQueueException
     * @throws \AMQPChannelException
     * @throws \AMQPConnectionException
     */
    public function consume(int $timeout = 0): void
    {
        if (null === $this->subscriber) {
            throw new \LogicException('There is no subscriber.');
        }

        /** @var AmqpConsumer $consumer */
        $consumer = $this->subscriber[0];
        $timeout = $timeout / 1000;

        $extQueue = new \AMQPQueue($this->context->getExtChannel());
        $extQueue->setName($consumer->getQueue()->getQueueName());
        for (;;) {
            $start ??= microtime(true);
            $extEnvelope = $extQueue->get();
            if (!$extEnvelope) {
                if (microtime(true) - $start > $timeout) {
                    return;
                }

                usleep(100000);

                continue;
            }

            $message = $this->context->convertMessage($extEnvelope);
            $message->setConsumerTag($consumer->getConsumerTag());

            call_user_func($this->subscriber[1], $message, $consumer);

            return;
        }
    }

    public function subscribe(Consumer $consumer, callable $callback): void
    {
        if (!$consumer instanceof AmqpConsumer) {
            throw new \InvalidArgumentException(sprintf('The consumer must be instance of "%s" got "%s"', AmqpConsumer::class, get_class($consumer)));
        }

        $this->subscriber = [$consumer, $callback];
    }

    public function unsubscribe(Consumer $consumer): void
    {
        $this->subscriber = null;
    }

    public function unsubscribeAll(): void
    {
        $this->subscriber = null;
    }
}
