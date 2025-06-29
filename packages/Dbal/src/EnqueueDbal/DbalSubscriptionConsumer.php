<?php

declare(strict_types=1);

namespace Enqueue\Dbal;

use Doctrine\DBAL\Connection;
use Ecotone\Messaging\Scheduling\DatePoint;
use Ecotone\Messaging\Scheduling\Duration;
use Interop\Queue\Consumer;
use Interop\Queue\SubscriptionConsumer;
use InvalidArgumentException;
use LogicException;

/**
 * licence MIT
 * code comes from https://github.com/php-enqueue/dbal
 */
class DbalSubscriptionConsumer implements SubscriptionConsumer
{
    use DbalConsumerHelperTrait;

    /**
     * @var DbalContext
     */
    private $context;

    /**
     * an item contains an array: [DbalConsumer $consumer, callable $callback];.
     *
     * @var array
     */
    private $subscribers;

    /**
     * @var Connection
     */
    private $dbal;

    /**
     * Default 20 minutes in milliseconds.
     *
     * @var int
     */
    private $redeliveryDelay;

    /**
     * Time to wait between subscription requests in milliseconds.
     *
     * @var int
     */
    private $pollingInterval = 200;

    public function __construct(DbalContext $context)
    {
        $this->context = $context;
        $this->dbal = $this->context->getDbalConnection();
        $this->subscribers = [];

        $this->redeliveryDelay = 1200000;
    }

    /**
     * Get interval between retrying failed messages in milliseconds.
     */
    public function getRedeliveryDelay(): int
    {
        return $this->redeliveryDelay;
    }

    public function setRedeliveryDelay(int $redeliveryDelay): self
    {
        $this->redeliveryDelay = $redeliveryDelay;

        return $this;
    }

    public function getPollingInterval(): int
    {
        return $this->pollingInterval;
    }

    public function setPollingInterval(int $msec): self
    {
        $this->pollingInterval = $msec;

        return $this;
    }

    public function consume(int $timeout = 0): void
    {
        if (empty($this->subscribers)) {
            throw new LogicException('No subscribers');
        }

        $queueNames = [];
        foreach (array_keys($this->subscribers) as $queueName) {
            $queueNames[$queueName] = $queueName;
        }

        $timeout /= 1000;
        $stopConsumptionDate = $timeout > 0 ? $this->now()->add(Duration::seconds($timeout)) : null;
        $redeliveryDelay = $this->getRedeliveryDelay() / 1000; // milliseconds to seconds

        $currentQueueNames = [];
        $queueConsumed = false;
        while (true) {
            if (empty($currentQueueNames)) {
                $currentQueueNames = $queueNames;
                $queueConsumed = false;
            }

            $this->removeExpiredMessages();
            $this->redeliverMessages();

            if ($message = $this->fetchMessage($currentQueueNames, $redeliveryDelay)) {
                $queueConsumed = true;

                /**
                 * @var DbalConsumer $consumer
                 * @var callable     $callback
                 */
                [$consumer, $callback] = $this->subscribers[$message->getQueue()];

                if (false === call_user_func($callback, $message, $consumer)) {
                    return;
                }

                unset($currentQueueNames[$message->getQueue()]);
            } else {
                $currentQueueNames = [];

                if (! $queueConsumed) {
                    $this->context->getClock()->sleep(Duration::milliseconds($this->getPollingInterval()));
                }
            }

            if ($stopConsumptionDate && $this->now() >= $stopConsumptionDate) {
                return;
            }
        }
    }

    /**
     * @param DbalConsumer $consumer
     */
    public function subscribe(Consumer $consumer, callable $callback): void
    {
        if (false == $consumer instanceof DbalConsumer) {
            throw new InvalidArgumentException(sprintf('The consumer must be instance of "%s" got "%s"', DbalConsumer::class, get_class($consumer)));
        }

        $queueName = $consumer->getQueue()->getQueueName();
        if (array_key_exists($queueName, $this->subscribers)) {
            if ($this->subscribers[$queueName][0] === $consumer && $this->subscribers[$queueName][1] === $callback) {
                return;
            }

            throw new InvalidArgumentException(sprintf('There is a consumer subscribed to queue: "%s"', $queueName));
        }

        $this->subscribers[$queueName] = [$consumer, $callback];
    }

    /**
     * @param DbalConsumer $consumer
     */
    public function unsubscribe(Consumer $consumer): void
    {
        if (false == $consumer instanceof DbalConsumer) {
            throw new InvalidArgumentException(sprintf('The consumer must be instance of "%s" got "%s"', DbalConsumer::class, get_class($consumer)));
        }

        $queueName = $consumer->getQueue()->getQueueName();

        if (false == array_key_exists($queueName, $this->subscribers)) {
            return;
        }

        if ($this->subscribers[$queueName][0] !== $consumer) {
            return;
        }

        unset($this->subscribers[$queueName]);
    }

    public function unsubscribeAll(): void
    {
        $this->subscribers = [];
    }

    protected function getContext(): DbalContext
    {
        return $this->context;
    }

    protected function getConnection(): Connection
    {
        return $this->dbal;
    }

    protected function now(): DatePoint
    {
        return $this->context->getClock()->now();
    }
}
