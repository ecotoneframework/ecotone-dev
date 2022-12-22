<?php

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\InMemoryChannelResolver;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\PollableChannel;

abstract class EnqueueMessageChannelBuilder implements MessageChannelBuilder
{
    protected EnqueueInboundChannelAdapterBuilder $inboundChannelAdapter;
    protected EnqueueOutboundChannelAdapterBuilder $outboundChannelAdapter;

    public function __construct(EnqueueInboundChannelAdapterBuilder $inboundChannelAdapterBuilder, EnqueueOutboundChannelAdapterBuilder $outboundChannelAdapterBuilder)
    {
        $this->inboundChannelAdapter = $inboundChannelAdapterBuilder;
        $this->outboundChannelAdapter = $outboundChannelAdapterBuilder;

        $this->withHeaderMapping('*');
    }

    public function getInboundChannelAdapter(): EnqueueInboundChannelAdapterBuilder
    {
        return $this->inboundChannelAdapter;
    }

    public function getOutboundChannelAdapter(): EnqueueOutboundChannelAdapterBuilder
    {
        return $this->outboundChannelAdapter;
    }

    public function isPollable(): bool
    {
        return true;
    }

    public function withHeaderMapping(string $headerMapper): self
    {
        $this->getInboundChannelAdapter()->withHeaderMapper($headerMapper);
        $this->getOutboundChannelAdapter()->withHeaderMapper($headerMapper);

        return $this;
    }

    public function withReceiveTimeout(int $timeoutInMilliseconds): self
    {
        $this->getInboundChannelAdapter()->withReceiveTimeout($timeoutInMilliseconds);

        return $this;
    }

    public function withDefaultTimeToLive(int $timeInMilliseconds): self
    {
        $this->getOutboundChannelAdapter()->withDefaultTimeToLive($timeInMilliseconds);

        return $this;
    }

    public function withDefaultDeliveryDelay(int $timeInMilliseconds): self
    {
        $this->getOutboundChannelAdapter()->withDefaultDeliveryDelay($timeInMilliseconds);

        return $this;
    }

    public function withDefaultConversionMediaType(string $mediaType): self
    {
        $this->getOutboundChannelAdapter()->withDefaultConversionMediaType($mediaType);

        return $this;
    }

    public function withAutoDeclare(bool $autoDeclare): self
    {
        $this->getInboundChannelAdapter()->withDeclareOnStartup($autoDeclare);
        $this->getOutboundChannelAdapter()->withAutoDeclareOnSend($autoDeclare);

        return $this;
    }

    public function getDefaultConversionMediaType(): ?MediaType
    {
        return $this->getOutboundChannelAdapter()->getDefaultConversionMediaType();
    }

    public function getRequiredReferenceNames(): array
    {
        return array_merge($this->getInboundChannelAdapter()->getRequiredReferences(), $this->getOutboundChannelAdapter()->getRequiredReferenceNames());
    }

    public function getMessageChannelName(): string
    {
        return $this->getInboundChannelAdapter()->getMessageChannelName();
    }

    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        return array_merge(
            $this->getInboundChannelAdapter()->resolveRelatedInterfaces($interfaceToCallRegistry),
            $this->getOutboundChannelAdapter()->resolveRelatedInterfaces($interfaceToCallRegistry)
        );
    }

    public function build(ReferenceSearchService $referenceSearchService): PollableChannel
    {
        /** @var ServiceConfiguration|null $serviceConfiguration */
        $serviceConfiguration = $referenceSearchService->has(ServiceConfiguration::class) ? $referenceSearchService->get(ServiceConfiguration::class) : null;
        $pollingMetadata = PollingMetadata::create('');

        if (!$this->getDefaultConversionMediaType() && $serviceConfiguration && $serviceConfiguration->getDefaultSerializationMediaType()) {
            $this->withDefaultConversionMediaType($serviceConfiguration->getDefaultSerializationMediaType());
        }

        if ($serviceConfiguration && $serviceConfiguration->getDefaultErrorChannel()) {
            $pollingMetadata = $pollingMetadata
                ->setErrorChannelName($serviceConfiguration->getDefaultErrorChannel());
        }
        if ($serviceConfiguration && $serviceConfiguration->getDefaultMemoryLimitInMegabytes()) {
            $pollingMetadata = $pollingMetadata
                ->setMemoryLimitInMegaBytes($serviceConfiguration->getDefaultMemoryLimitInMegabytes());
        }
        if ($serviceConfiguration && $serviceConfiguration->getConnectionRetryTemplate()) {
            $pollingMetadata = $pollingMetadata
                ->setConnectionRetryTemplate($serviceConfiguration->getConnectionRetryTemplate());
        }

        $inMemoryChannelResolver = InMemoryChannelResolver::createEmpty();

        return new EnqueueMessageChannel(
            $this->inboundChannelAdapter->createInboundChannelAdapter($inMemoryChannelResolver, $referenceSearchService, $pollingMetadata),
            $this->outboundChannelAdapter->build($inMemoryChannelResolver, $referenceSearchService)
        );
    }
}
