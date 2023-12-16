<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\DynamicChannel;

use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Support\Assert;

final class RoundRobinChannelBuilder implements MessageChannelBuilder
{
    /**
     * @param string[] $receivingChannelNames
     * @param string[] $sendingChannelNames
     * @param MessageChannelBuilder[] $internalMessageChannels
     */
    private function __construct(
        private string $thisMessageChannelName,
        private array $sendingChannelNames,
        private array $receivingChannelNames,
        private array $internalMessageChannels = []
    ) {
        Assert::allInstanceOfType($internalMessageChannels, MessageChannelBuilder::class);
    }

    /**
     * @param string[] $receivingChannelNames
     * @param string[] $sendingChannelNames
     */
    public static function createWithDifferentChannels(
        string $thisMessageChannelName,
        array $sendingChannelNames,
        array $receivingChannelNames,
        array $internalMessageChannels = []
    ): self
    {
        return new self(
            $thisMessageChannelName,
            $sendingChannelNames,
            $receivingChannelNames,
            $internalMessageChannels
        );
    }

    /**
     * @param string[] $channelNames
     */
    public static function create(
        string $thisMessageChannelName,
        array $channelNames,
        array $internalMessageChannels = []
    ): self
    {
        return new self(
            $thisMessageChannelName,
            $channelNames,
            $channelNames,
            $internalMessageChannels
        );
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            RoundRobinChannel::class,
            [
                $this->thisMessageChannelName,
                Reference::to(ChannelResolver::class),
                $this->sendingChannelNames,
                $this->receivingChannelNames,
                Reference::to(LoggingGateway::class),
                array_map(
                    fn(MessageChannelBuilder $channelBuilder, $key) => ['channel' => $channelBuilder->compile($builder), 'name' => is_int($key) ? $channelBuilder->getMessageChannelName() : $key],
                    $this->internalMessageChannels,
                    array_keys($this->internalMessageChannels)
                )
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