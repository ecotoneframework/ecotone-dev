<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use AMQPChannelException;
use AMQPConnectionException;
use AMQPException;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueHeader;
use Ecotone\Enqueue\EnqueueInboundChannelAdapter;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;
use Enqueue\AmqpLib\AmqpContext;
use Interop\Amqp\AmqpMessage;
use Interop\Queue\Consumer;
use Interop\Queue\Message as EnqueueMessage;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage as PhpAmqpLibMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * AMQP Stream Inbound Channel Adapter
 * Handles stream-specific consumption using direct basic_consume with stream offset support
 *
 * licence Enterprise
 */
class AmqpStreamInboundChannelAdapter extends EnqueueInboundChannelAdapter implements CancellableAmqpStreamConsumer
{
    public const X_STREAM_OFFSET_HEADER = 'x-stream-offset';
    private bool $initialized = false;
    private QueueChannel $queueChannel;
    private ?string $consumerTag = null;
    private BatchCommitCoordinator $batchCommitCoordinator;

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
        private int                     $prefetchCount,
        private int                     $commitInterval = 1,
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

        // Initialize the batch commit coordinator
        $this->batchCommitCoordinator = new BatchCommitCoordinator(
            $this->commitInterval,
            $this->positionTracker,
            $this->getConsumerId(),
        );
    }

    public function initialize(): void
    {
        $this->amqpAdmin->declareQueueWithBindings($this->queueName, $this->cachedConnectionFactory->createContext());
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
    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        try {
            if ($message = $this->queueChannel->receive()) {
                $streamPosition = $message->getHeaders()->get(self::X_STREAM_OFFSET_HEADER);

                // sometimes AMQP does redeliver same messages from end of given batch
                if ($this->batchCommitCoordinator->isOffsetAlreadyProcessed($streamPosition)) {
                    return $this->receiveWithTimeout($pollingMetadata);
                }

                return $message;
            }

            if ($this->declareOnStartup && $this->initialized === false) {
                $this->initialize();
                $this->initialized = true;
            }

            /** @var AmqpContext $context */
            $context = $this->cachedConnectionFactory->createContext();
            Assert::isTrue(method_exists($context, 'getLibChannel'), 'Stream consumption requires AMQP library connection.');
            $libChannel = $context->getLibChannel();

            $this->startStreamConsuming($context);

            // Wait for messages with the specified timeout
            $timeout = $pollingMetadata->getExecutionTimeLimitInMilliseconds() ?: $this->receiveTimeoutInMilliseconds;
            $timeoutInSeconds = $timeout > 0 ? $timeout / 1000.0 : 10.0;

            // Keep calling wait() in a loop while the consumer is active
            // This is crucial for low prefetch values - each wait() processes one message frame
            // With prefetch=1, we need to loop to process multiple messages
            while ($libChannel->is_consuming()) {
                try {
                    $libChannel->wait(null, false, $timeoutInSeconds);

                    // After first successful wait, use short timeout for draining buffered messages
                    $timeoutInSeconds = 0.05;
                } catch (AMQPTimeoutException) {
                    // No more messages available
                    break;
                }
            }

            return $this->queueChannel->receive();
        } catch (AMQPException|AMQPIOException $exception) {
            $this->stopStreamConsuming();
            $this->connectionFactory->reconnect();
            throw new ConnectionException('Failed to connect to AMQP broker', 0, $exception);
        }
    }

    private function startStreamConsuming(AmqpContext $context): void
    {
        // Commit any pending offset from previous batch and reset for new batch
        $this->batchCommitCoordinator->commitPendingAndReset(ignoreCommitInterval: true);
        $this->stopStreamConsuming();

        $libChannel = $context->getLibChannel();
        $libChannel->basic_qos(0, $this->prefetchCount, false);

        $offset = $this->startingPositionOffset;
        if (is_numeric($offset)) {
            $offset = (int) $offset;
        }

        $consumerId = $this->getConsumerId();
        $savedPosition = $this->positionTracker->loadPosition($consumerId);
        if ($savedPosition !== null) {
            $offset = (int)$savedPosition;
        }

        $arguments = new AMQPTable([self::X_STREAM_OFFSET_HEADER => $offset]);

        $this->consumerTag = $libChannel->basic_consume(
            queue: $this->queueName,
            consumer_tag: $consumerId,
            no_local: false,
            no_ack: false,
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
                $this->publisherConnectionFactory,
                $this->batchCommitCoordinator
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
                /** @var AmqpContext $context */
                $context = $this->cachedConnectionFactory->createContext();
                $context->getLibChannel()->basic_cancel($this->consumerTag);
            } catch (Throwable) {
                // Ignore errors during cleanup
            }
            $this->consumerTag = null;
        }
    }

    public function onConsumerStop(): void
    {
        $this->batchCommitCoordinator->commitPendingAndReset(ignoreCommitInterval: true);

        $this->cancelStreamConsumer();
        parent::onConsumerStop();
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
