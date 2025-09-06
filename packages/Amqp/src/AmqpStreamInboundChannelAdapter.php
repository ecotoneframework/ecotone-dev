<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Amqp\AmqpReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueInboundChannelAdapter;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;
use Interop\Amqp\AmqpMessage;
use Interop\Queue\Consumer;
use Interop\Queue\Message as EnqueueMessage;
use AMQPChannelException;
use AMQPConnectionException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * AMQP Stream Inbound Channel Adapter
 * Handles stream-specific consumption using basic_consume with stream offset support
 * 
 * licence Apache-2.0
 */
class AmqpStreamInboundChannelAdapter extends EnqueueInboundChannelAdapter
{
    private bool $initialized = false;
    private QueueChannel $queueChannel;

    public function __construct(
        private CachedConnectionFactory $cachedConnectionFactory,
        private AmqpAdmin $amqpAdmin,
        bool $declareOnStartup,
        string $queueName,
        int $receiveTimeoutInMilliseconds,
        InboundMessageConverter $inboundMessageConverter,
        ConversionService $conversionService,
        private LoggingGateway $loggingGateway,
        private string $streamOffset = 'next',
    ) {
        parent::__construct(
            $cachedConnectionFactory,
            $declareOnStartup,
            $queueName,
            $receiveTimeoutInMilliseconds,
            $inboundMessageConverter,
            $conversionService
        );
        $this->queueChannel = QueueChannel::create();
    }

    public function initialize(): void
    {
        $this->amqpAdmin->declareQueueWithBindings($this->queueName, $this->connectionFactory->createContext());
    }

    /**
     * @param AmqpMessage $sourceMessage
     */
    public function enrichMessage(EnqueueMessage $sourceMessage, MessageBuilder $targetMessage): MessageBuilder
    {
        if ($sourceMessage->getContentType()) {
            $targetMessage = $targetMessage->setContentType(MediaType::parseMediaType($sourceMessage->getContentType()));
        }

        return $targetMessage;
    }

    /**
     * Stream-specific receive implementation using subscription consumer with stream offset
     */
    public function receiveWithTimeout(int $timeout = 0): ?Message
    {
        try {
            if ($this->declareOnStartup && $this->initialized === false) {
                $this->initialize();
                $this->initialized = true;
            }

            /** @var AmqpReconnectableConnectionFactory $connectionFactory */
            $connectionFactory = $this->connectionFactory->getInnerConnectionFactory();
            $queueChannel = $this->queueChannel;

            // Prepare consumer arguments for stream channels with stream offset
            $consumerArguments = ['x-stream-offset' => ['S', $this->streamOffset]];

            $subscriptionConsumer = $connectionFactory->getSubscriptionConsumer($this->queueName, function (EnqueueMessage $receivedMessage, Consumer $consumer) use ($queueChannel) {
                $message = $this->inboundMessageConverter->toMessage($receivedMessage, $consumer, $this->conversionService, $this->cachedConnectionFactory);
                $message = $this->enrichMessage($receivedMessage, $message);

                $queueChannel->send($message->build());

                return false;
            }, $consumerArguments);

            $timeout = $timeout ?: $this->receiveTimeoutInMilliseconds;
            /** Timeout equal 0, will ignore any POSIX signals */
            $subscriptionConsumer->consume($timeout <= 0 ? 10000 : $timeout);

            return $this->queueChannel->receive();
        } catch (AMQPConnectionException|AMQPChannelException|AMQPIOException $exception) {
            $this->connectionFactory->reconnect();
            throw new ConnectionException('Failed to connect to AMQP broker', 0, $exception);
        }
    }

    public function connectionException(): array
    {
        return [AMQPConnectionException::class, AMQPChannelException::class, AMQPIOException::class];
    }
}
