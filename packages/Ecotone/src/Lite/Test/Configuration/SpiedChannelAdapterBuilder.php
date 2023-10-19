<?php

declare(strict_types=1);

namespace Ecotone\Lite\Test\Configuration;

use Ecotone\Messaging\Channel\ChannelInterceptorBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Precedence;
use InvalidArgumentException;

final class SpiedChannelAdapterBuilder implements ChannelInterceptorBuilder
{
    public function __construct(private string $relatedChannel)
    {
    }

    public function relatedChannelName(): string
    {
        return $this->relatedChannel;
    }

    public function getPrecedence(): int
    {
        return Precedence::DEFAULT_PRECEDENCE;
    }

    public function compile(ContainerMessagingBuilder $builder): Definition
    {
        if (! $builder->has(MessageCollectorHandler::class)) {
            throw new InvalidArgumentException("Can't spy channel {$this->relatedChannel} without MessageCollectorHandler registered in container");
        }
        return new Definition(
            SpiecChannelAdapter::class,
            [
                $this->relatedChannel,
                new Reference(MessageCollectorHandler::class),
            ]
        );
    }
}
