<?php

declare(strict_types=1);

namespace Ecotone\Redis;

use Ecotone\Api\Redis\RedisConnectionReference;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapterBuilder;
use Ecotone\Enqueue\HttpReconnectableConnectionFactory;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Support\LicensingException;

/**
 * licence Apache-2.0
 */
final class RedisOutboundChannelAdapterBuilder extends EnqueueOutboundChannelAdapterBuilder
{
    private bool $batchPublishing = false;

    private function __construct(private string $queueName, private string $connectionFactoryReferenceName)
    {
        $this->initialize($connectionFactoryReferenceName);
    }

    public static function createWith(string $queueName, string $connectionFactoryReferenceName = RedisConnectionReference::DEFAULT): self
    {
        return new self(
            $queueName,
            $connectionFactoryReferenceName
        );
    }

    public function withBatchPublishing(bool $batchPublishing = true): self
    {
        $this->batchPublishing = $batchPublishing;

        return $this;
    }

    public function isBatchPublishingEnabled(): bool
    {
        return $this->batchPublishing;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        if ($this->batchPublishing && ! $builder->getServiceConfiguration()->isRunningForEnterprise()) {
            throw LicensingException::create('High Throughput Publishing is available only with Ecotone Enterprise licence. See https://docs.ecotone.tech/enterprise for details.');
        }

        $connectionFactory = new Definition(CachedConnectionFactory::class, [
            new Definition(HttpReconnectableConnectionFactory::class, [
                new Reference($this->connectionFactoryReferenceName),
            ]),
        ], 'createFor');

        $outboundMessageConverter = new Definition(OutboundMessageConverter::class, [
            $this->headerMapper,
            $this->defaultConversionMediaType,
            $this->defaultDeliveryDelay,
            $this->defaultTimeToLive,
            $this->defaultPriority,
            [],
        ]);

        return new Definition(RedisOutboundChannelAdapter::class, [
            $connectionFactory,
            $this->queueName,
            $this->autoDeclare,
            $outboundMessageConverter,
            new Reference(ConversionService::REFERENCE_NAME),
            $this->batchPublishing,
        ]);
    }
}
