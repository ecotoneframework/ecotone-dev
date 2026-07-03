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
        $publishedMessages = [];
        foreach ($batchMessage->getEntries() as $entry) {
            $entryMessage = MessageBuilder::withPayload($entry['payload'])
                ->setMultipleHeaders($entry['headers'])
                ->build();

            $this->publish($entryMessage);
            $publishedMessages[] = $entryMessage;
        }

        if ($this->canPublishAsynchronously()) {
            $this->registerPendingDelivery($publishedMessages);

            return;
        }

        $this->awaitPublisherConfirmsSynchronously();
    }

    private function publish(Message $message): void
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

        $messageToSend
            ->setDeliveryMode($this->defaultPersistentDelivery ? AmqpMessage::DELIVERY_MODE_PERSISTENT : AmqpMessage::DELIVERY_MODE_NON_PERSISTENT);

        if ($this->publisherConfirms) {
            Assert::isFalse($this->amqpTransactionInterceptor->isRunningInTransaction(), 'Cannot use publisher acknowledgments together with transactions. Please disable one of them.');
        }

        $this->connectionFactory->createContext();
        $this->connectionFactory->getProducer()
            ->setTimeToLive($outboundMessage->getTimeToLive())
            ->setDelayStrategy($this->delayStrategy ?? new HeadersExchangeDelayStrategy())
            ->setDeliveryDelay($outboundMessage->getDeliveryDelay())
//            this allow for having queue per delay instead of queue per delay + exchangeName
            ->send(new AmqpTopic($exchangeName), $messageToSend);
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
