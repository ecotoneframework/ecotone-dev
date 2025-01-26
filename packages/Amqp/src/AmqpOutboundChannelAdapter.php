<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use AMQPChannelException;
use AMQPConnectionException;
use Ecotone\Amqp\Transaction\AmqpTransactionInterceptor;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Enqueue\AmqpExt\AmqpContext;
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
        private CachedConnectionFactory $connectionFactory,
        private AmqpAdmin $amqpAdmin,
        private string $exchangeName,
        private ?string $routingKey,
        private ?string $routingKeyFromHeaderName,
        private ?string $exchangeFromHeaderName,
        private bool $defaultPersistentDelivery,
        private bool $autoDeclare,
        private bool $deliveryGuarantee,
        private OutboundMessageConverter $outboundMessageConverter,
        private ConversionService $conversionService,
        private AmqpTransactionInterceptor $amqpTransactionInterceptor,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handle(Message $message): void
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

        /** @var AmqpContext $context */
        $context = $this->connectionFactory->createContext();
        if ($this->deliveryGuarantee && !$this->amqpTransactionInterceptor->isRunningInTransaction()) {
            /** Ensures no messages are lost along the way when heartbeat is lost and ensures messages was peristed on the Broker side. Without this message can be simply "swallowed" without throwing exception */
            $context->getExtChannel()->confirmSelect();
        }

        $this->connectionFactory->getProducer()
            ->setTimeToLive($outboundMessage->getTimeToLive())
            ->setDelayStrategy(new HeadersExchangeDelayStrategy())
            ->setDeliveryDelay($outboundMessage->getDeliveryDelay())
//            this allow for having queue per delay instead of queue per delay + exchangeName
            ->send(new AmqpTopic($exchangeName), $messageToSend);

        if ($this->deliveryGuarantee && !$this->amqpTransactionInterceptor->isRunningInTransaction()) {
            $context->getExtChannel()->waitForConfirm();
        }
    }
}
