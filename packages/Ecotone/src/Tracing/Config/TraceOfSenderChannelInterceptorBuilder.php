<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Config;

use Ecotone\Messaging\Channel\ChannelInterceptorBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\PrecedenceChannelInterceptor;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Tracing\TraceOfSenderChannelInterceptor;
use Ecotone\Tracing\Tracer;

/**
 * licence Apache-2.0
 */
final class TraceOfSenderChannelInterceptorBuilder implements ChannelInterceptorBuilder
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
        return PrecedenceChannelInterceptor::TRACE_OF_SENDER_PRECEDENCE;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(TraceOfSenderChannelInterceptor::class, [new Reference(Tracer::class), new Reference(MessageBuilder::class)]);
    }
}
