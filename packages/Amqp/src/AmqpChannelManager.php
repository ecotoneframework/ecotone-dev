<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Messaging\Channel\Manager\ChannelManager;
use Ecotone\Messaging\Config\Container\Definition;
use Interop\Amqp\AmqpQueue;
use Interop\Queue\ConnectionFactory;

/**
 * Channel manager for AMQP message channels.
 * Handles initialization and deletion of AMQP queues and exchanges.
 *
 * licence Apache-2.0
 */
final class AmqpChannelManager implements ChannelManager
{
    public function __construct(
        private string $channelName,
        private string $queueName,
        private ConnectionFactory $connectionFactory,
        private AmqpAdmin $amqpAdmin,
        private bool $shouldAutoInitialize,
        private bool $isStreamChannel = false,
    ) {
    }

    public function getChannelName(): string
    {
        return $this->channelName;
    }

    public function getChannelType(): string
    {
        return $this->isStreamChannel ? 'amqp_stream' : 'amqp';
    }

    public function initialize(): void
    {
        if ($this->isInitialized()) {
            return;
        }

        $context = $this->connectionFactory->createContext();
        $this->amqpAdmin->declareQueueWithBindings($this->queueName, $context);
    }

    public function delete(): void
    {
        $context = $this->connectionFactory->createContext();
        $queue = $this->amqpAdmin->getQueueByName($this->queueName);
        $context->deleteQueue($queue);
    }

    public function isInitialized(): bool
    {
        try {
            $context = $this->connectionFactory->createContext();
            $queue = $this->amqpAdmin->getQueueByName($this->queueName);
            
            // Use passive declaration to check if queue exists
            $passiveQueue = clone $queue;
            $passiveQueue->addFlag(AmqpQueue::FLAG_PASSIVE);
            $context->declareQueue($passiveQueue);
            
            return true;
        } catch (\Exception $e) {
            // Queue doesn't exist or other error
            return false;
        }
    }

    public function shouldBeInitializedAutomatically(): bool
    {
        return $this->shouldAutoInitialize;
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [
            $this->channelName,
            $this->queueName,
            $this->connectionFactory,
            $this->amqpAdmin,
            $this->shouldAutoInitialize,
            $this->isStreamChannel,
        ]);
    }
}

