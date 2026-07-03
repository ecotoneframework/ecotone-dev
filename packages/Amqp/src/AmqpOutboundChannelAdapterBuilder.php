<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Amqp\Transaction\AmqpTransactionInterceptor;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapterBuilder;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingRegistry;
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

    public const DEFAULT_ASYNC_PUBLISHING_TIMEOUT = 5000;

    private string $amqpConnectionFactoryReferenceName;
    private string $defaultRoutingKey = '';
    private ?string $routingKeyFromHeader = null;
    private ?string $exchangeFromHeader = null;
    private string $exchangeName;
    private bool $defaultPersistentDelivery = self::DEFAULT_PERSISTENT_MODE;
    private array $staticHeadersToAdd = [];
    private bool $publisherConfirms = true;
    private ?string $delayStrategyReferenceName = null;
    private bool $asyncPublishing = false;
    private int $asyncPublishingTimeout = self::DEFAULT_ASYNC_PUBLISHING_TIMEOUT;
    private ?string $asyncPublishingChannelName = null;

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

    public function withAsyncPublishing(bool $enabled = true, ?int $timeoutInMilliseconds = null): self
    {
        $this->asyncPublishing = $enabled;
        if ($timeoutInMilliseconds !== null) {
            $this->asyncPublishingTimeout = $timeoutInMilliseconds;
        }

        return $this;
    }

    public function isAsyncPublishingEnabled(): bool
    {
        return $this->asyncPublishing;
    }

    public function withAsyncPublishingChannelName(string $channelName): self
    {
        $this->asyncPublishingChannelName = $channelName;

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
        if ($this->asyncPublishing) {
            if (! $builder->getServiceConfiguration()->isRunningForEnterprise()) {
                throw LicensingException::create('Asynchronous publishing is available only with Ecotone Enterprise licence.');
            }
            Assert::isTrue($this->publisherConfirms, 'Asynchronous publishing requires publisher confirms to be enabled.');
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
            $this->delayStrategyReferenceName ? new Reference($this->delayStrategyReferenceName) : null,
            new Reference(AsyncPublishingRegistry::class),
            $this->asyncPublishing,
            $this->asyncPublishingTimeout,
            $this->asyncPublishingChannelName ?? $this->exchangeName,
        ]);
    }
}
