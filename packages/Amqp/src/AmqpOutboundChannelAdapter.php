<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Amqp\Transaction\AmqpTransactionInterceptor;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingFailedException;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingRegistry;
use Ecotone\Messaging\Channel\AsyncPublishing\FailedDelivery;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Config\ConfigurationException;
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
        private AsyncPublishingRegistry    $asyncPublishingRegistry,
        private ?DelayStrategy             $delayStrategy = null,
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
        if ($payload instanceof BatchMessage && ! $this->asyncPublishing) {
            throw ConfigurationException::create(sprintf('Sending BatchMessage over `%s` requires async publishing to be enabled. Enable it with withAsyncPublishing(), available as part of Ecotone Enterprise.', $this->channelName !== '' ? $this->channelName : $this->exchangeName));
        }

        $messagesToPublish = $payload instanceof BatchMessage
            ? array_map(
                fn (array $entry): Message => MessageBuilder::withPayload($entry['payload'])->setMultipleHeaders($entry['headers'])->build(),
                $payload->getEntries(),
            )
            : [$message];

        if ($messagesToPublish === []) {
            return;
        }

        $publishRecords = $this->publishMessages($messagesToPublish);

        if ($publishRecords !== [] && $this->canPublishAsynchronously()) {
            $this->registerPendingDelivery($publishRecords);

            return;
        }

        $this->awaitPublisherConfirmsSynchronously($publishRecords);
    }

    public function isAsyncPublishingEnabled(): bool
    {
        return $this->asyncPublishing;
    }

    /**
     * @param Message[] $messages
     * @return array<int, array{message: Message, deliveryTag: int, correlationId: string}>
     */
    private function publishMessages(array $messages): array
    {
        $context = $this->connectionFactory->createContext();
        if ($context instanceof AmqpLibContext) {
            return $this->publishThroughSingleBatchWrite($messages, $context);
        }

        $publishRecords = [];
        foreach ($messages as $message) {
            $publishRecords[] = $this->publish($message);
        }

        return array_values(array_filter($publishRecords));
    }

    /**
     * @param Message[] $messages
     * @return array<int, array{message: Message, deliveryTag: int, correlationId: string}>
     */
    private function publishThroughSingleBatchWrite(array $messages, AmqpLibContext $context): array
    {
        $preparedEntries = [];
        $delayedMessages = [];
        foreach ($messages as $message) {
            [$interopMessage, $exchangeName, $deliveryDelay] = $this->prepareInteropMessage($message);

            if ($deliveryDelay) {
                $delayedMessages[] = $message;

                continue;
            }

            $preparedEntries[] = [$message, $interopMessage, $exchangeName];
        }

        $publishRecords = [];
        foreach ($delayedMessages as $delayedMessage) {
            $publishRecords[] = $this->publish($delayedMessage);
        }

        $libChannel = $context->getLibChannel();
        foreach ($preparedEntries as [$message, $interopMessage, $exchangeName]) {
            $libChannel->batch_basic_publish(
                $this->convertToLibMessage($interopMessage),
                $exchangeName,
                $interopMessage->getRoutingKey() ?? '',
                mandatory: (bool) ($interopMessage->getFlags() & AmqpMessage::FLAG_MANDATORY),
            );
            $publishRecords[] = $this->recordPublishedMessage($message, $interopMessage);
        }

        if ($preparedEntries !== []) {
            $libChannel->publish_batch();
        }

        return array_values(array_filter($publishRecords));
    }

    private function convertToLibMessage(AmqpMessage $interopMessage): LibAMQPMessage
    {
        $amqpProperties = $interopMessage->getHeaders();
        if ($applicationProperties = $interopMessage->getProperties()) {
            $amqpProperties['application_headers'] = new AMQPTable($applicationProperties);
        }

        return new LibAMQPMessage($interopMessage->getBody(), $amqpProperties);
    }

    /**
     * @return array{message: Message, deliveryTag: int, correlationId: string}|null
     */
    private function publish(Message $message): ?array
    {
        [$messageToSend, $exchangeName, $deliveryDelay, $timeToLive] = $this->prepareInteropMessage($message);

        $this->connectionFactory->getProducer()
            ->setTimeToLive($timeToLive)
            ->setDelayStrategy($this->delayStrategy ??= new HeadersExchangeDelayStrategy())
            ->setDeliveryDelay($deliveryDelay)
//            this allow for having queue per delay instead of queue per delay + exchangeName
            ->send(new AmqpTopic($exchangeName), $messageToSend);

        return $this->recordPublishedMessage($message, $messageToSend);
    }

    /**
     * @return array{message: Message, deliveryTag: int, correlationId: string}|null
     */
    private function recordPublishedMessage(Message $message, AmqpMessage $interopMessage): ?array
    {
        if (! $this->publisherConfirms) {
            return null;
        }

        $confirmations = $this->getPublisherConfirmations();
        if ($confirmations === null) {
            return null;
        }

        $correlationId = (string) $interopMessage->getProperty(AmqpPublisherConfirmations::PUBLISH_BATCH_ID_PROPERTY, '');
        $resolveTagThroughCorrelation = $this->connectionFactory->createContext() instanceof AmqpLibContext;

        return [
            'message' => $message,
            'deliveryTag' => $confirmations->recordPublishedMessage($resolveTagThroughCorrelation ? $correlationId : ''),
            'correlationId' => $correlationId,
        ];
    }

    private function getPublisherConfirmations(): ?AmqpPublisherConfirmations
    {
        $innerConnectionFactory = $this->connectionFactory->getInnerConnectionFactory();

        return $innerConnectionFactory instanceof AmqpReconnectableConnectionFactory
            ? $innerConnectionFactory->getPublisherConfirmations()
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
            $messageToSend->setProperty(AmqpPublisherConfirmations::PUBLISH_BATCH_ID_PROPERTY, bin2hex(random_bytes(8)));
        }

        return [$messageToSend, $exchangeName, $outboundMessage->getDeliveryDelay(), $timeToLive];
    }

    private function canPublishAsynchronously(): bool
    {
        return $this->asyncPublishing
            && $this->publisherConfirms
            && $this->asyncPublishingRegistry->isScopeActive();
    }

    /**
     * @param array<int, array{message: Message, deliveryTag: int, correlationId: string}> $publishRecords
     */
    private function registerPendingDelivery(array $publishRecords): void
    {
        $this->asyncPublishingRegistry->register(
            $this->channelName,
            new AmqpPendingDelivery(
                $this->connectionFactory->createContext(),
                $publishRecords,
                $this->asyncPublishingTimeout,
                $this->channelName,
                $this->getPublisherConfirmations(),
            ),
        );
    }

    /**
     * @param array<int, array{message: Message, deliveryTag: int, correlationId: string}> $publishRecords
     */
    private function awaitPublisherConfirmsSynchronously(array $publishRecords): void
    {
        if (! $this->publisherConfirms || $this->amqpTransactionInterceptor->isRunningInTransaction()) {
            return;
        }

        $context = $this->connectionFactory->createContext();
        if ($publishRecords === []) {
            $timeoutInSeconds = $this->asyncPublishingTimeout / 1000;
            if ($context instanceof AmqpLibContext) {
                $context->getLibChannel()->wait_for_pending_acks_returns($timeoutInSeconds);
            } elseif ($context instanceof AmqpExtContext) {
                $context->getExtChannel()->waitForConfirm($timeoutInSeconds);
            }

            return;
        }

        $deliveryResult = (new AmqpPendingDelivery(
            $context,
            $publishRecords,
            $this->asyncPublishingTimeout,
            $this->channelName,
            $this->getPublisherConfirmations(),
        ))->awaitDelivery();

        if ($deliveryResult->isSuccessful()) {
            return;
        }

        if ($this->asyncPublishing) {
            throw AsyncPublishingFailedException::withFailedDeliveries($deliveryResult->getFailedDeliveries());
        }

        throw new RuntimeException(implode('; ', array_unique(array_map(
            fn (FailedDelivery $failedDelivery): string => $failedDelivery->getFailureReason(),
            $deliveryResult->getFailedDeliveries(),
        ))));
    }
}
