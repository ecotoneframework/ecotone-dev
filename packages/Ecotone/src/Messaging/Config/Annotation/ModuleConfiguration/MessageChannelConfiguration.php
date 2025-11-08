<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration;

final class MessageChannelConfiguration
{
    /**
     * @param array<string, string> $sharedChannels
     */
    public function __construct(private array $sharedChannels = [])
    {
    }

    public function isShared(string $channelName): bool
    {
        return in_array($channelName, $this->sharedChannels);
    }
}