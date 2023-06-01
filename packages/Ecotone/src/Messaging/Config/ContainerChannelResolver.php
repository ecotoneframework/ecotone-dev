<?php

namespace Ecotone\Messaging\Config;

use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\MessageChannel;
use Psr\Container\ContainerInterface;

class ContainerChannelResolver implements ChannelResolver
{
    public function __construct(private ContainerInterface $channels)
    {
    }

    /**
     * @inheritDoc
     */
    public function resolve($channelName): MessageChannel
    {
        if ($channelName instanceof MessageChannel) {
            return $channelName;
        }

        return $this->channels->get($channelName);
    }
}