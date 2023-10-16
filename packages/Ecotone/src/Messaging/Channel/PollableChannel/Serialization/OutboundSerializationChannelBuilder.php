<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\PollableChannel\Serialization;

use Ecotone\Messaging\Channel\ChannelInterceptor;
use Ecotone\Messaging\Channel\ChannelInterceptorBuilder;
use Ecotone\Messaging\Config\Container\ConfigurationVariableReference;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\PrecedenceChannelInterceptor;
use function DI\factory;

final class OutboundSerializationChannelBuilder implements ChannelInterceptorBuilder
{
    public function __construct(
        private string $relatedChannel,
        private HeaderMapper $headerMapper,
        private ?MediaType $channelConversionMediaType
    ) {
    }

    public function relatedChannelName(): string
    {
        return $this->relatedChannel;
    }

    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        return [];
    }

    public function getPrecedence(): int
    {
        return PrecedenceChannelInterceptor::MESSAGE_SERIALIZATION;
    }

    public function compile(ContainerMessagingBuilder $builder): Definition
    {
        return new Definition(OutboundSerializationChannelInterceptor::class, [
            new Definition(OutboundMessageConverter::class, [
                $this->headerMapper,
                $this->channelConversionMediaType ?: new Reference('config.defaultSerializationMediaType'),
            ]),
            Reference::to(ConversionService::REFERENCE_NAME),
        ]);
    }
}
