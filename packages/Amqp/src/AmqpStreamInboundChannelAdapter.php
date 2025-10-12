<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Amqp\AmqpReconnectableConnectionFactory;
use Ecotone\Amqp\AmqpStreamAcknowledgeCallback;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueHeader;
use Ecotone\Enqueue\EnqueueInboundChannelAdapter;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\Assert;
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
class AmqpStreamInboundChannelAdapter extends EnqueueInboundChannelAdapter implements CancellableAmqpStreamConsumer
{
    private bool $initialized = false;
    private QueueChannel $queueChannel;
    private ?string $consumerTag = null;

    public function __construct(
        private CachedConnectionFactory $cachedConnectionFactory,
        private AmqpAdmin               $amqpAdmin,
        bool                            $declareOnStartup,
        string                          $queueName,
        int                             $receiveTimeoutInMilliseconds,
        InboundMessageConverter         $inboundMessageConverter,
        ConversionService               $conversionService,
        private LoggingGateway          $loggingGateway,
        private ConsumerPositionTracker $positionTracker,
        private string                  $endpointId,
        private string                  $startingPositionOffset,
        private CachedConnectionFactory $publisherConnectionFactory,
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
     *
     * How this works:
     * 1. basic_consume registers a callback that gets invoked for each message
     * 2. wait() processes one AMQP frame at a time from the socket
     * 3. Each message delivery is one frame, so wait() processes one message per call
     * 4. The callback stores messages in queueChannel for retrieval
     * 5. We call wait() in a loop to drain all available messages from the stream
     *
     * Why the loop is necessary:
     * - When consuming from "first" or numeric offset, RabbitMQ sends all historical messages rapidly
     * - Each wait() call processes only ONE message frame
     * - Without the loop, we'd only get one message per receiveWithTimeout() call
     * - The loop with short timeout drains all buffered messages efficiently
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
            Assert::isTrue(method_exists($context, 'getLibChannel'), 'Stream consumption requires AMQP library connection.');
            $libChannel = $context->getLibChannel();

            // Check if we already have messages in the queue channel from previous wait() calls
            $existingMessage = $this->queueChannel->receive();
            if ($existingMessage !== null) {
                return $existingMessage;
            }

            // Start consuming if not already started
            if ($this->consumerTag === null) {
                $this->startStreamConsuming($context);
            }

            // Wait for messages with the specified timeout
            $timeout = $timeout ?: $this->receiveTimeoutInMilliseconds;
            $timeoutInSeconds = $timeout > 0 ? $timeout / 1000.0 : 10.0;

            try {
                // First wait with full timeout - this processes ONE message frame
                // As it process one frame (one message), it's not enough to trigger it only once as we won't fetch everything from tcp buffer
                $libChannel->wait(null, false, $timeoutInSeconds);
            } catch (AMQPTimeoutException) {
                // No messages arrived within timeout
                return null;
            }

            // Drain any additional messages that are already buffered
            // This is important for stream offsets like "first" where many messages arrive rapidly
            // We use a short timeout (50ms) to quickly drain buffered messages without blocking (no network wait!)
            while (true) {
                try {
                    $libChannel->wait(null, false, 0.05);
                } catch (AMQPTimeoutException) {
                    // No more buffered messages
                    break;
                }
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

        $offset = $this->startingPositionOffset;
        if (is_numeric($offset)) {
            $offset = (int) $offset;
        }

        $consumerId = $this->getConsumerId();
        $savedPosition = $this->positionTracker->loadPosition($consumerId);
        if ($savedPosition !== null) {
            $offset = (int)$savedPosition;
        }

        $arguments = new AMQPTable(['x-stream-offset' => $offset]);

        $this->consumerTag = $libChannel->basic_consume(
            queue: $this->queueName,
            consumer_tag: $consumerId,
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
            $headers = $amqpHeaders->getNativeData();

            // Extract stream offset from message headers (convert to string)
            $streamOffset = isset($headers['x-stream-offset']) ? (string)$headers['x-stream-offset'] : null;

            $enqueueMessage = $context->createMessage(
                $amqpMessage->getBody(),
                $headers,
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

            // Create acknowledge callback with position tracking
            $acknowledgeCallback = AmqpStreamAcknowledgeCallback::create(
                $amqpMessage,
                $this->loggingGateway,
                $this->cachedConnectionFactory,
                $this->inboundMessageConverter->getFinalFailureStrategy(),
                true, // Auto-acknowledge for streams
                $this->positionTracker,
                $this->getConsumerId(),
                $streamOffset,
                $this,
                $this->queueName,
                $this->publisherConnectionFactory
            );

            $message = $message->setHeader(
                EnqueueHeader::HEADER_ACKNOWLEDGE,
                $acknowledgeCallback
            );

            $this->queueChannel->send($message->build());

            return false;
        };
    }

    /**
     * Get consumer ID for position tracking (endpointId:queueName)
     * This allows the same endpoint to track position across multiple queues
     */
    private function getConsumerId(): string
    {
        return $this->endpointId . ':' . $this->queueName;
    }

    /**
     * Cancel the stream consumer and clear buffered messages
     * This allows the consumer to restart from the last committed position
     */
    public function cancelStreamConsumer(): void
    {
        $this->stopStreamConsuming();

        // Clear any buffered messages in the queue channel
        // This ensures we restart fresh from the committed offset
        while ($this->queueChannel->receive() !== null) {
            // Drain all buffered messages
        }
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
