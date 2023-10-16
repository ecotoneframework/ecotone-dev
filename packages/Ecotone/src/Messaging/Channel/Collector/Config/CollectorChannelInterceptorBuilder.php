<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Collector\Config;

use Ecotone\Messaging\Channel\ChannelInterceptorBuilder;
use Ecotone\Messaging\Channel\Collector\MessageCollectorChannelInterceptor;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\PrecedenceChannelInterceptor;

final class CollectorChannelInterceptorBuilder implements ChannelInterceptorBuilder
{
    public function __construct(private string $collectedChannel, private Reference $collectorStorageReference)
    {
    }

    public function relatedChannelName(): string
    {
        return $this->collectedChannel;
    }

    public function getPrecedence(): int
    {
        return PrecedenceChannelInterceptor::COLLECTOR_PRECEDENCE;
    }

    public function compile(ContainerMessagingBuilder $builder): Definition
    {
        return new Definition(
            MessageCollectorChannelInterceptor::class,
            [
                $this->collectorStorageReference,
                new Reference('logger'),
            ]
        );
    }

}
