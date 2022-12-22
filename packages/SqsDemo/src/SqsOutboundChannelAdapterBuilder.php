<?php

declare(strict_types=1);

namespace Test\SqsDemo;

use Ecotone\Dbal\DbalOutboundChannelAdapter;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapterBuilder;
use Ecotone\Enqueue\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Sqs\SqsConnectionFactory;

final class SqsOutboundChannelAdapterBuilder extends EnqueueOutboundChannelAdapterBuilder
{
    private function __construct(private string $queueName, private string $connectionFactoryReferenceName)
    {
        $this->initialize($connectionFactoryReferenceName);
    }

    public static function create(string $queueName, string $connectionFactoryReferenceName = DbalConnectionFactory::class): self
    {
        return new self($queueName, $connectionFactoryReferenceName);
    }

    public function build(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): DbalOutboundChannelAdapter
    {
        /** @var SqsConnectionFactory $connectionFactory */
        $connectionFactory = $referenceSearchService->get($this->connectionFactoryReferenceName);
        /** @var ConversionService $conversionService */
        $conversionService = $referenceSearchService->get(ConversionService::REFERENCE_NAME);

        $headerMapper = DefaultHeaderMapper::createWith([], $this->headerMapper, $conversionService);
        return new DbalOutboundChannelAdapter(
            CachedConnectionFactory::createFor(new DbalReconnectableConnectionFactory($connectionFactory)),
            $this->queueName,
            $this->autoDeclare,
            new OutboundMessageConverter($headerMapper, $conversionService, $this->defaultConversionMediaType, $this->defaultDeliveryDelay, $this->defaultTimeToLive, $this->defaultPriority, [])
        );
    }
}