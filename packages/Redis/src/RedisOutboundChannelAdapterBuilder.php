<?php

declare(strict_types=1);

namespace Ecotone\Redis;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapterBuilder;
use Ecotone\Enqueue\HttpReconnectableConnectionFactory;
use Ecotone\Enqueue\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\MessageConverter\DefaultHeaderMapper;
use Ecotone\Messaging\MessageHandler;
use Enqueue\Redis\RedisConnectionFactory;

final class RedisOutboundChannelAdapterBuilder extends EnqueueOutboundChannelAdapterBuilder
{
    private function __construct(private string $queueName, private string $connectionFactoryReferenceName)
    {
        $this->initialize($connectionFactoryReferenceName);
    }

    public static function createWith(string $queueName, string $connectionFactoryReferenceName = RedisConnectionFactory::class): self
    {
        return new self(
            $queueName,
            $connectionFactoryReferenceName
        );
    }

    public function build(ChannelResolver $channelResolver, ReferenceSearchService $referenceSearchService): MessageHandler
    {
        /** @var RedisConnectionFactory $connectionFactory */
        $connectionFactory = $referenceSearchService->get($this->connectionFactoryReferenceName);
        /** @var ConversionService $conversionService */
        $conversionService = $referenceSearchService->get(ConversionService::REFERENCE_NAME);

        $headerMapper = DefaultHeaderMapper::createWith(
            [],
            $this->headerMapper,
            $conversionService
        );
        return new RedisOutboundChannelAdapter(
            CachedConnectionFactory::createFor(new HttpReconnectableConnectionFactory($connectionFactory)),
            $this->queueName,
            $this->autoDeclare,
            new OutboundMessageConverter(
                $headerMapper,
                $conversionService,
                $this->defaultConversionMediaType,
                $this->defaultDeliveryDelay,
                $this->defaultTimeToLive,
                $this->defaultPriority,
                []
            )
        );
    }
}
