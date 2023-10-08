<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Collector\Config;

use Ecotone\Messaging\Channel\ChannelInterceptor;
use Ecotone\Messaging\Channel\ChannelInterceptorBuilder;
use Ecotone\Messaging\Channel\Collector\CollectorStorage;
use Ecotone\Messaging\Channel\Collector\MessageCollectorChannelInterceptor;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingHandlerBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\PrecedenceChannelInterceptor;

final class CollectorChannelInterceptorBuilder implements ChannelInterceptorBuilder, CompilableBuilder
{
    public function __construct(private string $collectedChannel)
    {
    }

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
        return new MessageCollectorChannelInterceptor(
            $this->collector,
            $referenceSearchService->get(LoggingHandlerBuilder::LOGGER_REFERENCE)
        );
    }

    public function compile(ContainerMessagingBuilder $builder): Reference|Definition|null
    {
        return new Definition(
            MessageCollectorChannelInterceptor::class,
            [
                new Reference(CollectorStorage::class),
                new Reference('logger'),
            ]
        );
    }

}
