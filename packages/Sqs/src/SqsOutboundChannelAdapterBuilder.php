<?php

declare(strict_types=1);

namespace Ecotone\Sqs;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapterBuilder;
use Ecotone\Enqueue\HttpReconnectableConnectionFactory;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Enqueue\Sqs\SqsConnectionFactory;
use Ramsey\Uuid\Uuid;

final class SqsOutboundChannelAdapterBuilder extends EnqueueOutboundChannelAdapterBuilder
{
    private function __construct(private string $queueName, private string $connectionFactoryReferenceName)
    {
        $this->initialize($connectionFactoryReferenceName);
    }

    public static function create(string $queueName, string $connectionFactoryReferenceName = SqsConnectionFactory::class): self
    {
        return new self($queueName, $connectionFactoryReferenceName);
    }

    public function build(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): SqsOutboundChannelAdapter
    {
        /** @var SqsConnectionFactory $connectionFactory */
        $connectionFactory = $referenceSearchService->get($this->connectionFactoryReferenceName);
        /** @var ConversionService $conversionService */
        $conversionService = $referenceSearchService->get(ConversionService::REFERENCE_NAME);

        return new SqsOutboundChannelAdapter(
            CachedConnectionFactory::createFor(new HttpReconnectableConnectionFactory($connectionFactory)),
            $this->queueName,
            $this->autoDeclare,
            new OutboundMessageConverter($this->headerMapper, $this->defaultConversionMediaType, $this->defaultDeliveryDelay, $this->defaultTimeToLive, $this->defaultPriority, []),
            $conversionService
        );
    }

    public function compile(ContainerMessagingBuilder $builder): Reference|Definition|null
    {
        $connectionFactory = new Definition(CachedConnectionFactory::class, [
            new Definition(HttpReconnectableConnectionFactory::class, [
                new Reference($this->connectionFactoryReferenceName)
            ])
        ], 'createFor');

        $outboundMessageConverter = new Definition(OutboundMessageConverter::class, [
            $this->headerMapper,
            $this->defaultConversionMediaType,
            $this->defaultDeliveryDelay,
            $this->defaultTimeToLive,
            $this->defaultPriority,
            []
        ]);

        return $builder->register(Uuid::uuid4()->toString(), new Definition(SqsOutboundChannelAdapter::class, [
            $connectionFactory,
            $this->queueName,
            $this->autoDeclare,
            $outboundMessageConverter,
            new Reference(ConversionService::REFERENCE_NAME)
        ]));
    }
}
