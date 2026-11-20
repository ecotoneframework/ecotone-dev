<?php

declare(strict_types=1);

namespace Ecotone\Api\ExtensionObject;

use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 *
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 */
final class CoroutineMessageChannelBuilder implements MessageChannelBuilder
{
    private function __construct(public readonly string $messageChannelName)
    {
    }

    public static function create(string $messageChannelName): self
    {
        return new self($messageChannelName);
    }

    public function getMessageChannelName(): string
    {
        return $this->messageChannelName;
    }

    public function isPollable(): bool
    {
        return true;
    }

    public function isStreamingChannel(): bool
    {
        return false;
    }

    public function compile(MessagingContainerBuilder $builder): Definition
    {
        return new Definition(QueueChannel::class, [$this->messageChannelName]);
    }
}
