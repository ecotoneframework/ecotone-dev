<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Lite\Test\Configuration\MessageCollectorHandler;
use Ecotone\Lite\Test\Configuration\SpiecChannelAdapter;
use Ecotone\Messaging\Channel\ChannelInterceptor;
use Ecotone\Messaging\Channel\ChannelInterceptorBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Precedence;
use Ecotone\OpenTelemetry\TracingChannelInterceptor;
use OpenTelemetry\API\Trace\TracerInterface;

final class TracingChannelAdapterBuilder implements ChannelInterceptorBuilder
{
    public function __construct(private string $channelName) {}

    public function relatedChannelName(): string
    {
        return $this->channelName;
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
        return Precedence::DEFAULT_PRECEDENCE;
    }

    public function build(ReferenceSearchService $referenceSearchService): ChannelInterceptor
    {
        return new TracingChannelInterceptor($this->channelName, $referenceSearchService->get(TracerInterface::class));
    }
}
