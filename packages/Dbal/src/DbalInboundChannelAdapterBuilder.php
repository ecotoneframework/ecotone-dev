<?php

namespace Ecotone\Dbal;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueInboundChannelAdapterBuilder;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Endpoint\TaskExecutorChannelAdapter\TaskExecutorChannelAdapter;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\Scheduling\TaskExecutor;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\DbalDestination;
use Exception;

class DbalInboundChannelAdapterBuilder extends EnqueueInboundChannelAdapterBuilder
{
    /**
     * @var string
     */
    private $connectionReferenceName;
    /**
     * @var string
     */
    private $queueName;

    private function __construct(string $endpointId, string $queueName, ?string $requestChannelName, string $dbalConnectionReferenceName)
    {
        $this->connectionReferenceName = $dbalConnectionReferenceName;
        $this->queueName = $queueName;
        $this->initialize($endpointId, $requestChannelName, $dbalConnectionReferenceName);
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }

    public static function createWith(string $endpointId, string $queueName, ?string $requestChannelName, string $connectionReferenceName = DbalConnectionFactory::class): self
    {
        return new self($endpointId, $queueName, $requestChannelName, $connectionReferenceName);
    }

    public function createInboundChannelAdapter(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService, PollingMetadata $pollingMetadata): DbalInboundChannelAdapter
    {
        /** @var DbalConnection $connectionFactory */
        $connectionFactory = $referenceSearchService->get($this->connectionReferenceName);
        /** @var ConversionService $conversionService */
        $conversionService = $referenceSearchService->get(ConversionService::REFERENCE_NAME);

        $headerMapper = DefaultHeaderMapper::createWith($this->headerMapper, [], $conversionService);

        return new DbalInboundChannelAdapter(
            CachedConnectionFactory::createFor(new DbalReconnectableConnectionFactory($connectionFactory)),
            $this->buildGatewayFor($referenceSearchService, $channelResolver, $pollingMetadata),
            true,
            $this->queueName,
            $this->receiveTimeoutInMilliseconds,
            new InboundMessageConverter($this->getEndpointId(), $this->acknowledgeMode, DbalHeader::HEADER_ACKNOWLEDGE, $headerMapper)
        );
    }
}
