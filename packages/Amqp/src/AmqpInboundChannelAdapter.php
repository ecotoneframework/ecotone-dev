<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueInboundChannelAdapter;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\PollingConsumer\ConnectionException;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\Support\MessageBuilder;
use Interop\Amqp\AmqpMessage;
use Interop\Queue\Consumer;
use Interop\Queue\Message as EnqueueMessage;

/**
 * Class InboundEnqueueGateway
 * @package Ecotone\Amqp
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class AmqpInboundChannelAdapter extends EnqueueInboundChannelAdapter
{
    private bool $initialized = false;

    public function __construct(
        CachedConnectionFactory         $cachedConnectionFactory,
        InboundChannelAdapterEntrypoint $inboundAmqpGateway,
        private AmqpAdmin               $amqpAdmin,
        bool                            $declareOnStartup,
        string                          $queueName,
        int                             $receiveTimeoutInMilliseconds,
        InboundMessageConverter         $inboundMessageConverter
    ) {
        parent::__construct(
            $cachedConnectionFactory,
            $inboundAmqpGateway,
            $declareOnStartup,
            $queueName,
            $receiveTimeoutInMilliseconds,
            $inboundMessageConverter
        );
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

    public function receiveMessage(int $timeout = 0): ?Message
    {
        if ($this->declareOnStartup && $this->initialized === false) {
            $this->initialize();

            $this->initialized = true;
        }

        $context = $this->connectionFactory->createContext();
        $consumer = $this->connectionFactory->getConsumer(
            $this->connectionFactory->createContext()->createQueue($this->queueName)
        );
        $subscriptionConsumer = $context->createSubscriptionConsumer();

        $message = null;
        $subscriptionConsumer->subscribe($consumer, function(EnqueueMessage $receivedMessage, Consumer $consumer) use (&$message) {
            $message = $receivedMessage;

            return false;
        });

        try {
            $subscriptionConsumer->consume($timeout ?: $this->receiveTimeoutInMilliseconds);
            $subscriptionConsumer->unsubscribe($consumer);
        } catch (\Exception $exception) {
            $subscriptionConsumer->unsubscribe($consumer);

            throw $exception;
        }

        if (! $message) {
            return null;
        }

        $convertedMessage = $this->inboundMessageConverter->toMessage($message, $consumer);
        $convertedMessage = $this->enrichMessage($message, $convertedMessage);

        return $convertedMessage->build();
    }
}
