<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Enqueue\AmqpLib\AmqpConnectionFactory;

/**
 * licence Apache-2.0
 */
class AmqpStreamChannelBuilder extends EnqueueMessageChannelBuilder
{
    private string $channelName;

    private function __construct(
        string $channelName,
        string $amqpConnectionReferenceName,
        public readonly string $queueName,
        string $streamOffset
    ) {
        $this->channelName = $channelName;

        parent::__construct(
            AmqpStreamInboundChannelAdapterBuilder::create($channelName, $queueName, $streamOffset, $amqpConnectionReferenceName),
            AmqpOutboundChannelAdapterBuilder::createForDefaultExchange($amqpConnectionReferenceName)
                ->withDefaultRoutingKey($queueName)
                ->withAutoDeclareOnSend(true)
                ->withDefaultPersistentMode(true)
        );
    }

    /**
     * Create a stream channel with consume method enabled and stream queue type
     * 
     * @param string $channelName
     * @param string $startPosition Stream offset: 'first', 'last', 'next', or specific offset number
     * @param string $amqpConnectionReferenceName
     * @param string|null $queueName If null, channel name will be used as queue name
     * @return self
     */
    public static function create(
        string  $channelName,
        string  $startPosition = 'next',
        string  $amqpConnectionReferenceName = AmqpConnectionFactory::class,
        ?string $queueName = null
    ): self {
        $queueName ??= $channelName;

        return new self($channelName, $amqpConnectionReferenceName, $queueName, $startPosition);
    }

    public function getMessageChannelName(): string
    {
        return $this->channelName;
    }

    public function isPollable(): bool
    {
        return true;
    }

    public function __toString()
    {
        return sprintf('AMQP Stream Channel - %s', $this->channelName);
    }
}
