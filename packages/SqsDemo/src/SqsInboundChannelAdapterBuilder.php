<?php

declare(strict_types=1);

namespace Test\SqsDemo;

use Ecotone\Dbal\DbalHeader;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueInboundChannelAdapterBuilder;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\Scheduling\TaskExecutor;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Sqs\SqsConnectionFactory;

final class SqsInboundChannelAdapterBuilder extends EnqueueInboundChannelAdapterBuilder
{
    private function __construct(
        string         $endpointId,
        private string $queueName,
        ?string        $requestChannelName,
        private bool   $declareOnStartup,
        private string $connectionReferenceName
    )
    {
        $this->initialize($endpointId, $requestChannelName, $connectionReferenceName);
    }

    public static function createWith(string $endpointId, string $queueName, ?string $requestChannelName, bool $initializeOnStartup = true, string $connectionReferenceName = DbalConnectionFactory::class): self
    {
        return new self($endpointId, $queueName, $requestChannelName, $initializeOnStartup, $connectionReferenceName);
    }

    protected function createInboundChannelAdapter(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService, PollingMetadata $pollingMetadata): TaskExecutor
    {
        /** @var SqsConnectionFactory $connectionFactory */
        $connectionFactory = $referenceSearchService->get($this->connectionReferenceName);
        /** @var ConversionService $conversionService */
        $conversionService = $referenceSearchService->get(ConversionService::REFERENCE_NAME);

        $headerMapper = DefaultHeaderMapper::createWith($this->headerMapper, [], $conversionService);

        return new SqsInboundChannelAdapter(
            CachedConnectionFactory::createFor(new SqsReconnectableConnectionFactory($connectionFactory)),
            $this->buildGatewayFor($referenceSearchService, $channelResolver, $pollingMetadata),
            $this->declareOnStartup,
            $this->queueName,
            $this->receiveTimeoutInMilliseconds,
            new InboundMessageConverter($this->getEndpointId(), $this->acknowledgeMode, DbalHeader::HEADER_ACKNOWLEDGE, $headerMapper)
        );
    }
}