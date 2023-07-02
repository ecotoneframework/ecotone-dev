<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Collector\Config;

use Ecotone\Messaging\Channel\ChannelInterceptor;
use Ecotone\Messaging\Channel\ChannelInterceptorBuilder;
use Ecotone\Messaging\Channel\Collector\Collector;
use Ecotone\Messaging\Channel\Collector\CollectorChannelInterceptor;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\PrecedenceChannelInterceptor;

final class CollectorChannelInterceptorBuilder implements ChannelInterceptorBuilder
{
    public function __construct(private string $collectedChannel, private Collector $collector) {}

    public function relatedChannelName(): string
    {
        return $this->collectedChannel;
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
        return new CollectorChannelInterceptor($this->collectedChannel, $this->collector);
    }
}