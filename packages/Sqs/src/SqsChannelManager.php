<?php

declare(strict_types=1);

namespace Ecotone\Sqs;

use Ecotone\Messaging\Channel\Manager\ChannelManager;
use Ecotone\Messaging\Config\Container\Definition;
use Enqueue\Sqs\SqsContext;
use Exception;
use Interop\Queue\ConnectionFactory;

/**
 * Channel manager for SQS message channels.
 * Handles initialization and deletion of SQS queues.
 *
 * licence Apache-2.0
 */
final class SqsChannelManager implements ChannelManager
{
    public function __construct(
        private string $channelName,
        private string $queueName,
        private ConnectionFactory $connectionFactory,
        private bool $shouldAutoInitialize,
    ) {
    }

    public function getChannelName(): string
    {
        return $this->channelName;
    }

    public function getChannelType(): string
    {
        return 'sqs';
    }

    public function initialize(): void
    {
        if ($this->isInitialized()) {
            return;
        }

        /** @var SqsContext $context */
        $context = $this->connectionFactory->createContext();
        $context->declareQueue($context->createQueue($this->queueName));
    }

    public function delete(): void
    {
        /** @var SqsContext $context */
        $context = $this->connectionFactory->createContext();
        $queue = $context->createQueue($this->queueName);
        $context->deleteQueue($queue);
    }

    public function isInitialized(): bool
    {
        try {
            /** @var SqsContext $context */
            $context = $this->connectionFactory->createContext();
            $queue = $context->createQueue($this->queueName);
            // Use GetQueueUrl to check existence
            $context->getQueueUrl($queue);
            return true;
        } catch (Exception $e) {
            // QueueDoesNotExist exception or other error
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
            $this->shouldAutoInitialize,
        ]);
    }
}
