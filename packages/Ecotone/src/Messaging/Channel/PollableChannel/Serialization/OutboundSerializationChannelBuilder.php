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

    public function getRequiredReferenceNames(): array
    {
        return [];
    }

    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        return [];
    }

    public function getPrecedence(): int
    {
        return PrecedenceChannelInterceptor::MESSAGE_SERIALIZATION;
    }

    public function build(ReferenceSearchService $referenceSearchService): ChannelInterceptor
    {
        throw new \InvalidArgumentException("This builder is not supported");
        /** @var ServiceConfiguration $serviceConfiguration */
        $serviceConfiguration = $referenceSearchService->get(ServiceConfiguration::class);

        return new OutboundSerializationChannelInterceptor(
            new OutboundMessageConverter(
                $this->headerMapper,
                $this->channelConversionMediaType ?: MediaType::parseMediaType($serviceConfiguration->getDefaultSerializationMediaType())
            ),
            $referenceSearchService->get(ConversionService::REFERENCE_NAME)
        );
    }

    public function compile(ContainerMessagingBuilder $builder): Reference|Definition|null
    {
        return new Definition(OutboundSerializationChannelInterceptor::class, [
            new Definition(OutboundMessageConverter::class, [
                $this->headerMapper->getDefinition(),
                $this->channelConversionMediaType?->getDefinition() ?: new Reference('config.defaultSerializationMediaType'),
            ]),
            Reference::to(ConversionService::REFERENCE_NAME),
        ]);
    }
}
