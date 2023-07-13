<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Annotation\ModuleConfiguration\PollableChannel;

use Ecotone\Messaging\Handler\Recoverability\RetryTemplate;

class PollableChannelConfiguration
{
    private function __construct(private string $channelName, private RetryTemplate $retryTemplate)
    {
    }

    public static function create(string $channelName, RetryTemplate $retryTemplate): self
    {
        return new self($channelName, $retryTemplate);
    }

    public static function neverRetry(string $channelName): self
    {
        return new self($channelName, RetryTemplate::createNeverRetryTemplate());
    }

    public function getChannelName(): string
    {
        return $this->channelName;
    }

    public function getRetryTemplate(): RetryTemplate
    {
        return $this->retryTemplate;
    }
}