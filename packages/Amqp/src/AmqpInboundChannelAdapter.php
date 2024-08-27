<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use AMQPChannelException;
use AMQPConnectionException;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueHeader;
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

/**
 * Class InboundEnqueueGateway
 * @package Ecotone\Amqp
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class AmqpInboundChannelAdapter extends EnqueueInboundChannelAdapter
{
    private bool $initialized = false;
    private QueueChannel $queueChannel;

    public function __construct(
        private CachedConnectionFactory         $cachedConnectionFactory,
        private AmqpAdmin               $amqpAdmin,
        bool                            $declareOnStartup,
        string                          $queueName,
        int                             $receiveTimeoutInMilliseconds,
        InboundMessageConverter         $inboundMessageConverter,
        ConversionService $conversionService,
        private LoggingGateway $loggingGateway,
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

        return $targetMessage->setHeader(
            EnqueueHeader::HEADER_ACKNOWLEDGE,
            new AmqpAcknowledgeCallbackWraper(
                $targetMessage->getHeaderWithName(EnqueueHeader::HEADER_ACKNOWLEDGE),
                $this->cachedConnectionFactory,
                $this->loggingGateway,
            )
        );
    }

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
            $subscriptionConsumer = $connectionFactory->getSubscriptionConsumer($this->queueName, function (EnqueueMessage $receivedMessage, Consumer $consumer) use ($queueChannel) {
                $message = $this->inboundMessageConverter->toMessage($receivedMessage, $consumer, $this->conversionService);
                $message = $this->enrichMessage($receivedMessage, $message);

                $queueChannel->send($message->build());

                return false;
            });

            $subscriptionConsumer->consume($timeout ?: $this->receiveTimeoutInMilliseconds);

            return $this->queueChannel->receive();
        } catch (AMQPConnectionException|AMQPChannelException $exception) {
            $this->connectionFactory->reconnect();
            throw new ConnectionException('Failed to connect to AMQP broker', 0, $exception);
        }
    }

    public function connectionException(): string
    {
        return AMQPConnectionException::class;
    }
}
