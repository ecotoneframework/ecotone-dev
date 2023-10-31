<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Messaging\Channel\ChannelInterceptorBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Precedence;
use OpenTelemetry\API\Trace\TracerProviderInterface;

final class TracingChannelAdapterBuilder implements ChannelInterceptorBuilder
{
    public function __construct(private string $channelName)
    {
    }

    public function relatedChannelName(): string
    {
        return $this->channelName;
    }

    public function getPrecedence(): int
    {
        return Precedence::DEFAULT_PRECEDENCE;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(TracingChannelInterceptor::class, [
            $this->channelName,
            new Reference(TracerProviderInterface::class),
        ]);
    }
}
