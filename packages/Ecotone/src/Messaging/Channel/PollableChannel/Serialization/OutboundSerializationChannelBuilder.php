<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\PollableChannel\Serialization;

use Ecotone\Messaging\Channel\ChannelInterceptor;
use Ecotone\Messaging\Channel\ChannelInterceptorBuilder;
use Ecotone\Messaging\Channel\PollableChannel\SendRetries\SendRetryChannelInterceptor;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingHandlerBuilder;
use Ecotone\Messaging\Handler\Recoverability\RetryTemplate;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\PrecedenceChannelInterceptor;

final class OutboundSerializationChannelBuilder implements ChannelInterceptorBuilder
{
    public function __construct(
        private string $relatedChannel,
        private HeaderMapper $headerMapper,
        private ?MediaType $channelConversionMediaType
    )
    {
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
        return PrecedenceChannelInterceptor::COLLECTOR_PRECEDENCE;
    }

    public function build(ReferenceSearchService $referenceSearchService): ChannelInterceptor
    {


        return new OutboundSerializationChannelInterceptor(
            new OutboundMessageConverter(
                $this->headerMapper,
                $this->channelConversionMediaType
            ),
            $referenceSearchService->get(ConversionService::REFERENCE_NAME)
        );
    }
}
