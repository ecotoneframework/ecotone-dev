<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Amqp\Transaction\AmqpTransactionInterceptor;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapterBuilder;
use Ecotone\Messaging\Channel\DeliveryConfirmation\PendingDeliveryRegistry;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\LicensingException;

/**
 * licence Apache-2.0
 */
class AmqpOutboundChannelAdapterBuilder extends EnqueueOutboundChannelAdapterBuilder
{
    private const DEFAULT_PERSISTENT_MODE = true;

    public const DEFAULT_CONFIRMATION_TIMEOUT = 12000;

    private string $amqpConnectionFactoryReferenceName;
    private string $defaultRoutingKey = '';
    private ?string $routingKeyFromHeader = null;
    private ?string $exchangeFromHeader = null;
    private string $exchangeName;
    private bool $defaultPersistentDelivery = self::DEFAULT_PERSISTENT_MODE;
    private array $staticHeadersToAdd = [];
    private bool $publisherConfirms = true;
    private ?string $delayStrategyReferenceName = null;
    private bool $batchPublishing = false;
    private bool $nonBlockingConfirmation = false;
    private int $confirmationTimeout = self::DEFAULT_CONFIRMATION_TIMEOUT;
    private ?string $publishingChannelName = null;

    private function __construct(string $exchangeName, string $amqpConnectionFactoryReferenceName)
    {
        $this->amqpConnectionFactoryReferenceName = $amqpConnectionFactoryReferenceName;
        $this->exchangeName = $exchangeName;
        $this->initialize($amqpConnectionFactoryReferenceName);
    }

    public static function create(string $exchangeName, string $amqpConnectionFactoryReferenceName): self
    {
        return new self($exchangeName, $amqpConnectionFactoryReferenceName);
    }

    public static function createForDefaultExchange(string $amqpConnectionFactoryReferenceName): self
    {
        return new self('', $amqpConnectionFactoryReferenceName);
    }

    /**
     * @param string $routingKey
     *
     * @return AmqpOutboundChannelAdapterBuilder
     */
    public function withDefaultRoutingKey(string $routingKey): self
    {
        $this->defaultRoutingKey = $routingKey;

        return $this;
    }

    public function withPublisherConfirms(bool $publisherConfirms): self
    {
        $this->publisherConfirms = $publisherConfirms;

        return $this;
    }

    public function withHighThroughputPublishing(bool $batchPublishing = true, bool $nonBlockingConfirmation = true, ?int $confirmationTimeoutInMilliseconds = null): self
    {
        Assert::isTrue($confirmationTimeoutInMilliseconds === null || $confirmationTimeoutInMilliseconds > 0, 'Confirmation timeout must be a positive amount of milliseconds.');
        $this->batchPublishing = $batchPublishing;
        $this->nonBlockingConfirmation = $nonBlockingConfirmation;
        if ($confirmationTimeoutInMilliseconds !== null) {
            $this->confirmationTimeout = $confirmationTimeoutInMilliseconds;
        }

        return $this;
    }

    public function isBatchPublishingEnabled(): bool
    {
        return $this->batchPublishing;
    }

    public function isNonBlockingConfirmationEnabled(): bool
    {
        return $this->nonBlockingConfirmation;
    }

    public function withPublishingChannelName(string $channelName): self
    {
        $this->publishingChannelName = $channelName;

        return $this;
    }

    public function withDelayStrategy(string $delayStrategyReferenceName): self
    {
        $this->delayStrategyReferenceName = $delayStrategyReferenceName;

        return $this;
    }

    /**
     * @param string $headerName
     *
     * @return AmqpOutboundChannelAdapterBuilder
     */
    public function withRoutingKeyFromHeader(string $headerName): self
    {
        $this->routingKeyFromHeader = $headerName;

        return $this;
    }

    public function withStaticHeadersToEnrich(array $headers): self
    {
        $this->staticHeadersToAdd = $headers;

        return $this;
    }

    /**
     * @param string $exchangeName
     *
     * @return AmqpOutboundChannelAdapterBuilder
     */
    public function withExchangeFromHeader(string $exchangeName): self
    {
        $this->exchangeFromHeader = $exchangeName;

        return $this;
    }

    /**
     * @param bool $isPersistent
     *
     * @return AmqpOutboundChannelAdapterBuilder
     */
    public function withDefaultPersistentMode(bool $isPersistent): self
    {
        $this->defaultPersistentDelivery = $isPersistent;

        return $this;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        if ($this->batchPublishing || $this->nonBlockingConfirmation) {
            if (! $builder->getServiceConfiguration()->isRunningForEnterprise()) {
                throw LicensingException::create('High Throughput Publishing is available only with Ecotone Enterprise licence.');
            }
        }
        if ($this->nonBlockingConfirmation) {
            Assert::isTrue($this->publisherConfirms, 'Non blocking confirmation requires publisher confirms to be enabled.');
        }

        $connectionFactory = new Definition(CachedConnectionFactory::class, [
            new Definition(AmqpReconnectableConnectionFactory::class, [
                new Reference($this->amqpConnectionFactoryReferenceName),
                null,
                $this->publisherConfirms,
            ]),
        ], 'createFor');

        $outboundMessageConverter = new Definition(OutboundMessageConverter::class, [
            $this->headerMapper,
            $this->defaultConversionMediaType,
            $this->defaultDeliveryDelay,
            $this->defaultTimeToLive,
            $this->defaultPriority,
            $this->staticHeadersToAdd,
        ]);

        return new Definition(AmqpOutboundChannelAdapter::class, [
            $connectionFactory,
            $this->autoDeclare ? new Reference(AmqpAdmin::REFERENCE_NAME) : new Definition(AmqpAdmin::class, factory: 'createEmpty'),
            $this->exchangeName,
            $this->defaultRoutingKey,
            $this->routingKeyFromHeader,
            $this->exchangeFromHeader,
            $this->defaultPersistentDelivery,
            $this->autoDeclare,
            $this->publisherConfirms,
            $outboundMessageConverter,
            new Reference(ConversionService::REFERENCE_NAME),
            Reference::to(AmqpTransactionInterceptor::class),
            new Reference(PendingDeliveryRegistry::class),
            $this->delayStrategyReferenceName ? new Reference($this->delayStrategyReferenceName) : null,
            $this->batchPublishing,
            $this->nonBlockingConfirmation,
            $this->confirmationTimeout,
            $this->publishingChannelName ?? $this->exchangeName,
        ]);
    }
}
