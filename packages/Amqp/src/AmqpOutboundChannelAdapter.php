<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Amqp\Transaction\AmqpTransactionInterceptor;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingRegistry;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;
use Enqueue\AmqpExt\AmqpContext as AmqpExtContext;
use Enqueue\AmqpLib\AmqpContext as AmqpLibContext;
use Enqueue\AmqpTools\DelayStrategy;
use Interop\Amqp\AmqpMessage;
use Interop\Amqp\Impl\AmqpTopic;
use PhpAmqpLib\Message\AMQPMessage as LibAMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * @author  Dariusz Gafka <support@simplycodedsoftware.com>
 * @licence Apache-2.0
 */
/**
 * licence Apache-2.0
 */
class AmqpOutboundChannelAdapter implements MessageHandler
{
    /**
     * @var bool
     */
    private $initialized = false;

    public function __construct(
        private CachedConnectionFactory    $connectionFactory,
        private AmqpAdmin                  $amqpAdmin,
        private string                     $exchangeName,
        private ?string                    $routingKey,
        private ?string                    $routingKeyFromHeaderName,
        private ?string                    $exchangeFromHeaderName,
        private bool                       $defaultPersistentDelivery,
        private bool                       $autoDeclare,
        private bool                       $publisherConfirms,
        private OutboundMessageConverter   $outboundMessageConverter,
        private ConversionService          $conversionService,
        private AmqpTransactionInterceptor $amqpTransactionInterceptor,
        private ?DelayStrategy             $delayStrategy = null,
        private ?AsyncPublishingRegistry   $asyncPublishingRegistry = null,
        private bool                       $asyncPublishing = false,
        private int                        $asyncPublishingTimeout = AmqpOutboundChannelAdapterBuilder::DEFAULT_ASYNC_PUBLISHING_TIMEOUT,
        private string                     $channelName = '',
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handle(Message $message): void
    {
        if ($message->getPayload() instanceof BatchMessage) {
            $this->handleBatch($message->getPayload(), $message);

            return;
        }

        $this->publish($message);

        if ($this->canPublishAsynchronously()) {
            $this->registerPendingDelivery([$message]);

            return;
        }

        $this->awaitPublisherConfirmsSynchronously();
    }

    public function isAsyncPublishingEnabled(): bool
    {
        return $this->asyncPublishing;
    }

    private function handleBatch(BatchMessage $batchMessage, Message $carrierMessage): void
    {
        $entryMessages = [];
        foreach ($batchMessage->getEntries() as $entry) {
            $entryMessages[] = MessageBuilder::withPayload($entry['payload'])
                ->setMultipleHeaders($entry['headers'])
                ->build();
        }

        $context = $this->connectionFactory->createContext();
        if ($context instanceof AmqpLibContext) {
            $this->publishBatchThroughSingleWrite($entryMessages, $context);
        } else {
            foreach ($entryMessages as $entryMessage) {
                $this->publish($entryMessage);
            }
        }

        if ($this->canPublishAsynchronously()) {
            $this->registerPendingDelivery($entryMessages);

            return;
        }

        $this->awaitPublisherConfirmsSynchronously();
    }

    /**
     * @param Message[] $messages
     */
    private function publishBatchThroughSingleWrite(array $messages, AmqpLibContext $context): void
    {
        $libChannel = $context->getLibChannel();
        $anyMessageBatched = false;

        foreach ($messages as $message) {
            [$interopMessage, $exchangeName, $deliveryDelay] = $this->prepareInteropMessage($message);

            if ($deliveryDelay) {
                $this->publish($message);

                continue;
            }

            $amqpProperties = $interopMessage->getHeaders();
            if ($applicationProperties = $interopMessage->getProperties()) {
                $amqpProperties['application_headers'] = new AMQPTable($applicationProperties);
            }

            $libChannel->batch_basic_publish(
                new LibAMQPMessage($interopMessage->getBody(), $amqpProperties),
                $exchangeName,
                $interopMessage->getRoutingKey() ?? '',
            );
            $anyMessageBatched = true;
        }

        if ($anyMessageBatched) {
            $libChannel->publish_batch();
        }
    }

    private function publish(Message $message): void
    {
        [$messageToSend, $exchangeName, $deliveryDelay, $timeToLive] = $this->prepareInteropMessage($message);

        $this->connectionFactory->getProducer()
            ->setTimeToLive($timeToLive)
            ->setDelayStrategy($this->delayStrategy ?? new HeadersExchangeDelayStrategy())
            ->setDeliveryDelay($deliveryDelay)
//            this allow for having queue per delay instead of queue per delay + exchangeName
            ->send(new AmqpTopic($exchangeName), $messageToSend);
    }

    /**
     * @return array{0: \Interop\Amqp\Impl\AmqpMessage, 1: string, 2: int|null, 3: int|null}
     */
    private function prepareInteropMessage(Message $message): array
    {
        $exchangeName = $this->exchangeName;
        if ($this->exchangeFromHeaderName) {
            $exchangeName = $message->getHeaders()->containsKey($this->exchangeFromHeaderName) ? $message->getHeaders()->get($this->exchangeFromHeaderName) : $this->exchangeName;
        }
        if (! $this->initialized && $this->autoDeclare) {
            $this->amqpAdmin->declareExchangeWithQueuesAndBindings($exchangeName, $this->connectionFactory->createContext());
            $this->initialized = true;
        }

        $outboundMessage = $this->outboundMessageConverter->prepare($message, $this->conversionService);
        $messageToSend   = new \Interop\Amqp\Impl\AmqpMessage($outboundMessage->getPayload(), $outboundMessage->getHeaders(), []);

        if ($this->routingKeyFromHeaderName) {
            $routingKey = $message->getHeaders()->containsKey($this->routingKeyFromHeaderName) ? $message->getHeaders()->get($this->routingKeyFromHeaderName) : $this->routingKey;
        } else {
            $routingKey = $this->routingKey;
        }

        if ($outboundMessage->getContentType()) {
            $messageToSend->setContentType($outboundMessage->getContentType());
        }

        if (! is_null($routingKey) && $routingKey !== '') {
            $messageToSend->setRoutingKey($routingKey);
        }

        $timeToLive = $outboundMessage->getTimeToLive();
        if ($timeToLive !== null && $messageToSend->getExpiration() === null) {
            $messageToSend->setExpiration($timeToLive);
        }

        $messageToSend
            ->setDeliveryMode($this->defaultPersistentDelivery ? AmqpMessage::DELIVERY_MODE_PERSISTENT : AmqpMessage::DELIVERY_MODE_NON_PERSISTENT);

        if ($this->publisherConfirms) {
            Assert::isFalse($this->amqpTransactionInterceptor->isRunningInTransaction(), 'Cannot use publisher acknowledgments together with transactions. Please disable one of them.');
        }

        $this->connectionFactory->createContext();

        return [$messageToSend, $exchangeName, $outboundMessage->getDeliveryDelay(), $timeToLive];
    }

    private function canPublishAsynchronously(): bool
    {
        return $this->asyncPublishing
            && $this->publisherConfirms
            && $this->asyncPublishingRegistry !== null
            && $this->asyncPublishingRegistry->isScopeActive();
    }

    /**
     * @param Message[] $publishedMessages
     */
    private function registerPendingDelivery(array $publishedMessages): void
    {
        $this->asyncPublishingRegistry->register(
            $this->channelName,
            new AmqpPendingDelivery(
                $this->connectionFactory->createContext(),
                $publishedMessages,
                $this->asyncPublishingTimeout,
                $this->channelName,
            ),
        );
    }

    private function awaitPublisherConfirmsSynchronously(): void
    {
        if (! $this->publisherConfirms || $this->amqpTransactionInterceptor->isRunningInTransaction()) {
            return;
        }

        $context = $this->connectionFactory->createContext();
        if ($context instanceof AmqpLibContext) {
            $context->getLibChannel()->wait_for_pending_acks(5);
        } elseif ($context instanceof AmqpExtContext) {
            $context->getExtChannel()->waitForConfirm(5);
        }
    }
}
