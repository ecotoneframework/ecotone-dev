<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration\PollableChannel;

class PollableChannelConfiguration
{
    public function __construct(private string $channelName, private int $maxSendRetries)
    {
    }

    public function getChannelName(): string
    {
        return $this->channelName;
    }

    public function getMaxSendRetries(): int
    {
        return $this->maxSendRetries;
    }
}