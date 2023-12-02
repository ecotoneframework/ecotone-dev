<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\DynamicChannel;

use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ChannelResolver;

final class RoundRobinChannelBuilder implements MessageChannelBuilder
{
    /**
     * @param string[] $receivingChannelNames
     * @param string[] $sendingChannelNames
     */
    private function __construct(
        private string $thisMessageChannelName,
        private array $sendingChannelNames,
        private array $receivingChannelNames,
    ) {
    }

    /**
     * @param string[] $receivingChannelNames
     * @param string[] $sendingChannelNames
     */
    public static function create(
        string $thisMessageChannelName,
        array $sendingChannelNames,
        array $receivingChannelNames,
    ): self
    {
        return new self(
            $thisMessageChannelName,
            $sendingChannelNames,
            $receivingChannelNames
        );
    }

    /**
     * @param string[] $channelNames
     */
    public static function createWithSameChannels(
        string $thisMessageChannelName,
        array $channelNames
    ): self
    {
        return new self(
            $thisMessageChannelName,
            $channelNames,
            $channelNames
        );
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            RoundRobinChannel::class,
            [
                Reference::to(ChannelResolver::class),
                $this->sendingChannelNames,
                $this->receivingChannelNames
            ]
        );
    }

    public function getMessageChannelName(): string
    {
        return $this->thisMessageChannelName;
    }

    public function isPollable(): bool
    {
        return true;
    }
}