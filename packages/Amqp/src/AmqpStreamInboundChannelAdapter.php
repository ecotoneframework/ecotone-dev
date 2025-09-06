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
use Enqueue\AmqpLib\AmqpContext;
use Interop\Amqp\AmqpMessage;
use Interop\Queue\Consumer;
use Interop\Queue\Message as EnqueueMessage;
use AMQPChannelException;
use AMQPConnectionException;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage as PhpAmqpLibMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * AMQP Stream Inbound Channel Adapter
 * Handles stream-specific consumption using direct basic_consume with stream offset support
 *
 * licence Apache-2.0
 */
class AmqpStreamInboundChannelAdapter extends EnqueueInboundChannelAdapter
{
    private bool $initialized = false;
    private QueueChannel $queueChannel;
    private ?string $consumerTag = null;

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
     * Stream-specific receive implementation using direct basic_consume with stream offset
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

            /** @var AmqpContext $context */
            $context = $connectionFactory->createContext();
            $libChannel = $context->getLibChannel();

            while ($this->queueChannel->receive() !== null) {
                // Clear buffer
            }

            // Start consuming if not already started
            if ($this->consumerTag === null) {
                $this->startStreamConsuming($context);
            }

            $timeout = $timeout ?: $this->receiveTimeoutInMilliseconds;
            $timeoutInSeconds = $timeout > 0 ? $timeout / 1000.0 : 10.0;

            try {
                $libChannel->wait(null, false, $timeoutInSeconds);
            } catch (AMQPTimeoutException) {
                // Expected timeout for running out of time
                $this->loggingGateway->info('Stream consumption timeout reached');
            }

            return $this->queueChannel->receive();
        } catch (AMQPConnectionException|AMQPChannelException|AMQPIOException $exception) {
            $this->stopStreamConsuming();
            $this->connectionFactory->reconnect();
            throw new ConnectionException('Failed to connect to AMQP broker', 0, $exception);
        }
    }

    private function startStreamConsuming(AmqpContext $context): void
    {
        $libChannel = $context->getLibChannel();
        $libChannel->basic_qos(0, 100, false);

        $arguments = new AMQPTable(['x-stream-offset' => $this->streamOffset]);

        $this->consumerTag = $libChannel->basic_consume(
            queue: $this->queueName,
            consumer_tag: '',
            no_local: false,
            no_ack: false, // Important: we need manual ack for Ecotone's system
            exclusive: false,
            nowait: false,
            callback: $this->createStreamCallback($context),
            ticket: null,
            arguments: $arguments
        );
    }

    private function createStreamCallback(AmqpContext $context): callable
    {
        return function (PhpAmqpLibMessage $amqpMessage) use ($context) {
            /** @var AMQPTable $amqpHeaders */
            $amqpHeaders = $amqpMessage->get_properties()['application_headers'];
            $enqueueMessage = $context->createMessage(
                $amqpMessage->getBody(),
                $amqpHeaders->getNativeData(),
                [],
            );

            $consumer = $context->createConsumer($context->createQueue($this->queueName));
            $consumer->setConsumerTag($this->consumerTag);
            $message = $this->inboundMessageConverter->toMessage(
                $enqueueMessage,
                $consumer,
                $this->conversionService,
                $this->cachedConnectionFactory
            );
            $message = $this->enrichMessage($enqueueMessage, $message);

            $this->queueChannel->send($message->build());

            return false;
        };
    }

    private function stopStreamConsuming(): void
    {
        if ($this->consumerTag !== null) {
            try {
                /** @var AmqpReconnectableConnectionFactory $connectionFactory */
                $connectionFactory = $this->connectionFactory->getInnerConnectionFactory();
                /** @var AmqpContext $context */
                $context = $connectionFactory->createContext();
                $context->getLibChannel()->basic_cancel($this->consumerTag);
            } catch (\Throwable) {
                // Ignore errors during cleanup
            }
            $this->consumerTag = null;
        }
    }

    public function connectionException(): array
    {
        return [AMQPConnectionException::class, AMQPChannelException::class, AMQPIOException::class, AMQPChannelClosedException::class, AMQPConnectionClosedException::class];
    }

    public function __destruct()
    {
        $this->stopStreamConsuming();
    }
}
