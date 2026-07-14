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
use RuntimeException;

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
        $payload = $message->getPayload();
        $messagesToPublish = $payload instanceof BatchMessage
            ? array_map(
                fn (array $entry): Message => MessageBuilder::withPayload($entry['payload'])->setMultipleHeaders($entry['headers'])->build(),
                $payload->getEntries(),
            )
            : [$message];

        if ($messagesToPublish === []) {
            return;
        }

        $this->publishMessages($messagesToPublish);

        if ($this->canPublishAsynchronously()) {
            $this->registerPendingDelivery($messagesToPublish);

            return;
        }

        $this->awaitPublisherConfirmsSynchronously();
    }

    public function isAsyncPublishingEnabled(): bool
    {
        return $this->asyncPublishing;
    }

    /**
     * @param Message[] $messages
     */
    private function publishMessages(array $messages): void
    {
        $context = $this->connectionFactory->createContext();
        if ($context instanceof AmqpLibContext) {
            $this->publishThroughSingleBatchWrite($messages, $context);

            return;
        }

        foreach ($messages as $message) {
            $this->publish($message);
        }
    }

    /**
     * @param Message[] $messages
     */
    private function publishThroughSingleBatchWrite(array $messages, AmqpLibContext $context): void
    {
        $preparedEntries = [];
        $delayedMessages = [];
        foreach ($messages as $message) {
            [$interopMessage, $exchangeName, $deliveryDelay] = $this->prepareInteropMessage($message);

            if ($deliveryDelay) {
                $delayedMessages[] = $message;

                continue;
            }

            $preparedEntries[] = [$this->convertToLibMessage($interopMessage), $exchangeName, $interopMessage->getRoutingKey() ?? '', (bool) ($interopMessage->getFlags() & AmqpMessage::FLAG_MANDATORY)];
        }

        foreach ($delayedMessages as $delayedMessage) {
            $this->publish($delayedMessage);
        }

        $libChannel = $context->getLibChannel();
        foreach ($preparedEntries as [$libMessage, $exchangeName, $routingKey, $mandatory]) {
            $libChannel->batch_basic_publish($libMessage, $exchangeName, $routingKey, mandatory: $mandatory);
        }

        if ($preparedEntries !== []) {
            $libChannel->publish_batch();
        }
    }

    private function convertToLibMessage(AmqpMessage $interopMessage): LibAMQPMessage
    {
        $amqpProperties = $interopMessage->getHeaders();
        if ($applicationProperties = $interopMessage->getProperties()) {
            $amqpProperties['application_headers'] = new AMQPTable($applicationProperties);
        }

        return new LibAMQPMessage($interopMessage->getBody(), $amqpProperties);
    }

    private function publish(Message $message): void
    {
        [$messageToSend, $exchangeName, $deliveryDelay, $timeToLive] = $this->prepareInteropMessage($message);

        $this->connectionFactory->getProducer()
            ->setTimeToLive($timeToLive)
            ->setDelayStrategy($this->delayStrategy ??= new HeadersExchangeDelayStrategy())
            ->setDeliveryDelay($deliveryDelay)
//            this allow for having queue per delay instead of queue per delay + exchangeName
            ->send(new AmqpTopic($exchangeName), $messageToSend);

        $this->recordExtPublishedMessage();
    }

    private function recordExtPublishedMessage(): void
    {
        if (! $this->publisherConfirms) {
            return;
        }

        if ($this->connectionFactory->createContext() instanceof AmqpExtContext) {
            $this->getExtPublisherConfirmations()?->recordPublishedMessage();
        }
    }

    private function getExtPublisherConfirmations(): ?AmqpExtPublisherConfirmations
    {
        $innerConnectionFactory = $this->connectionFactory->getInnerConnectionFactory();

        return $innerConnectionFactory instanceof AmqpReconnectableConnectionFactory
            ? $innerConnectionFactory->getExtPublisherConfirmations()
            : null;
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
            $messageToSend->addFlag(AmqpMessage::FLAG_MANDATORY);
        }

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
                $this->getExtPublisherConfirmations(),
            ),
        );
    }

    private function awaitPublisherConfirmsSynchronously(): void
    {
        if (! $this->publisherConfirms || $this->amqpTransactionInterceptor->isRunningInTransaction()) {
            return;
        }

        $timeoutInSeconds = $this->asyncPublishingTimeout / 1000;
        $context = $this->connectionFactory->createContext();
        if ($context instanceof AmqpLibContext) {
            $context->getLibChannel()->wait_for_pending_acks_returns($timeoutInSeconds);
        } elseif ($context instanceof AmqpExtContext) {
            $extPublisherConfirmations = $this->getExtPublisherConfirmations();
            if ($extPublisherConfirmations === null) {
                $context->getExtChannel()->waitForConfirm($timeoutInSeconds);

                return;
            }

            $deadline = microtime(true) + $timeoutInSeconds;
            while ($extPublisherConfirmations->hasOutstandingConfirmations()) {
                $remainingSeconds = $deadline - microtime(true);
                if ($remainingSeconds <= 0) {
                    throw new RuntimeException('Timed out awaiting publisher confirms from RabbitMQ instance.');
                }

                $context->getExtChannel()->waitForConfirm($remainingSeconds);
            }
        }
    }
}
