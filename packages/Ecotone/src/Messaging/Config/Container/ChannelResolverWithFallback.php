<?php

namespace Ecotone\Messaging\Config\Container;

use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\MessageChannel;
use Psr\Container\ContainerInterface;

class ChannelResolverWithFallback implements ChannelResolver
{
    public function __construct(private ContainerInterface $container, private ?ChannelResolver $fallbackChannelResolver = null)
    {
    }

    public function resolve($channelName): MessageChannel
    {
        if ($channelName instanceof MessageChannel) {
            return $channelName;
        }
        if ($this->container->has(new ChannelReference($channelName))) {
            return $this->container->get(new ChannelReference($channelName));
        }
        return $this->fallbackChannelResolver?->resolve($channelName);
    }

    public function hasChannelWithName(string $channelName): bool
    {
        return $this->container->has(new ChannelReference($channelName)) || $this->fallbackChannelResolver?->hasChannelWithName($channelName);
    }
}