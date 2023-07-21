<?php

declare(strict_types=1);

namespace Ecotone\Redis;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueHeader;
use Ecotone\Enqueue\EnqueueInboundChannelAdapter;
use Ecotone\Enqueue\EnqueueInboundChannelAdapterBuilder;
use Ecotone\Enqueue\HttpReconnectableConnectionFactory;
use Ecotone\Enqueue\InboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Enqueue\Redis\RedisConnectionFactory;

final class RedisInboundChannelAdapterBuilder extends EnqueueInboundChannelAdapterBuilder
{
    public static function createWith(string $endpointId, string $queueName, ?string $requestChannelName, string $connectionReferenceName = RedisConnectionFactory::class): self
    {
        return new self($queueName, $endpointId, $requestChannelName, $connectionReferenceName);
    }

    public function createInboundChannelAdapter(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService, PollingMetadata $pollingMetadata): EnqueueInboundChannelAdapter
    {
        /** @var RedisConnectionFactory $connectionFactory */
        $connectionFactory = $referenceSearchService->get($this->connectionReferenceName);
        /** @var ConversionService $conversionService */
        $conversionService = $referenceSearchService->get(ConversionService::REFERENCE_NAME);

        $headerMapper = DefaultHeaderMapper::createWith($this->headerMapper, []);

        return new RedisInboundChannelAdapter(
            CachedConnectionFactory::createFor(new HttpReconnectableConnectionFactory($connectionFactory)),
            $this->buildGatewayFor($referenceSearchService, $channelResolver, $pollingMetadata),
            $this->declareOnStartup,
            $this->messageChannelName,
            $this->receiveTimeoutInMilliseconds,
            new InboundMessageConverter($this->getEndpointId(), $this->acknowledgeMode, $headerMapper, EnqueueHeader::HEADER_ACKNOWLEDGE),
            $conversionService
        );
    }
}
