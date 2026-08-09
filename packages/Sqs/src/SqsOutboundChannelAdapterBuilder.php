<?php

declare(strict_types=1);

namespace Ecotone\Sqs;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapterBuilder;
use Ecotone\Enqueue\HttpReconnectableConnectionFactory;
use Ecotone\Messaging\Channel\DeliveryConfirmation\PendingDeliveryRegistry;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\LicensingException;
use Enqueue\Sqs\SqsConnectionFactory;

/**
 * licence Apache-2.0
 */
final class SqsOutboundChannelAdapterBuilder extends EnqueueOutboundChannelAdapterBuilder
{
    public const DEFAULT_CONFIRMATION_TIMEOUT = 25000;

    private bool $batchPublishing = false;
    private bool $nonBlockingConfirmation = false;
    private int $confirmationTimeout = self::DEFAULT_CONFIRMATION_TIMEOUT;

    private function __construct(private string $queueName, private string $connectionFactoryReferenceName)
    {
        $this->initialize($connectionFactoryReferenceName);
    }

    public static function create(string $queueName, string $connectionFactoryReferenceName = SqsConnectionFactory::class): self
    {
        return new self($queueName, $connectionFactoryReferenceName);
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

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        if (($this->batchPublishing || $this->nonBlockingConfirmation) && ! $builder->getServiceConfiguration()->isRunningForEnterprise()) {
            throw LicensingException::create('High Throughput Publishing is available only with Ecotone Enterprise licence.');
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

        return new Definition(SqsOutboundChannelAdapter::class, [
            $connectionFactory,
            $this->queueName,
            $this->autoDeclare,
            $outboundMessageConverter,
            new Reference(ConversionService::REFERENCE_NAME),
            new Reference(PendingDeliveryRegistry::class),
            $this->batchPublishing,
            $this->nonBlockingConfirmation,
            $this->confirmationTimeout,
        ]);
    }
}
