<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\DynamicChannel;

use Ecotone\Messaging\Channel\DynamicChannel\ReceivingStrategy\CustomReceivingStrategy;
use Ecotone\Messaging\Channel\DynamicChannel\ReceivingStrategy\RoundRobinReceivingStrategy;
use Ecotone\Messaging\Channel\DynamicChannel\SendingStrategy\CustomSendingStrategy;
use Ecotone\Messaging\Channel\DynamicChannel\SendingStrategy\RoundRobinSendingStrategy;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Support\Assert;

final class DynamicMessageChannelBuilder implements MessageChannelBuilder
{
    /**
     * @param MessageChannelBuilder[] $internalMessageChannels
     */
    private function __construct(
        private string     $thisMessageChannelName,
        private Definition $channelSendingStrategy,
        private Definition $channelReceivingStrategy,
        private array      $internalMessageChannels = []
    ) {
        Assert::allInstanceOfType($internalMessageChannels, MessageChannelBuilder::class);
    }

    /**
     * @param string[] $receivingChannelNames
     * @param string[] $sendingChannelNames
     * @param MessageChannelBuilder[] $internalMessageChannels
     */
    public static function createRoundRobinWithDifferentChannels(
        string $thisMessageChannelName,
        array $sendingChannelNames,
        array $receivingChannelNames,
        array $internalMessageChannels = []
    ): self
    {
        return new self(
            $thisMessageChannelName,
            new Definition(RoundRobinSendingStrategy::class, [$sendingChannelNames]),
            new Definition(RoundRobinReceivingStrategy::class, [$receivingChannelNames]),
            $internalMessageChannels
        );
    }

    /**
     * @param string[] $channelNames
     * @param MessageChannelBuilder[] $internalMessageChannels
     */
    public static function createDefault(
        string $thisMessageChannelName,
        array $channelNames = [],
        array $internalMessageChannels = []
    ): self
    {
        return new self(
            $thisMessageChannelName,
            new Definition(RoundRobinSendingStrategy::class, [$channelNames]),
            new Definition(RoundRobinReceivingStrategy::class, [$channelNames]),
            $internalMessageChannels
        );
    }

    /**
     * @param string $requestChannelName Name of the inputChannel of Service Activator that will provide channel name to send message to
     */
    public function withCustomSendingStrategy(string $requestChannelName): self
    {
        $this->channelSendingStrategy = new Definition(CustomSendingStrategy::class, [
            Reference::to(MessagingEntrypoint::class),
            $requestChannelName
        ]);

        return $this;
    }

    /**
     * @param string $requestChannelName Name of the inputChannel of Service Activator that will provide channel name to poll message from
     */
    public function withCustomReceivingStrategy(string $requestChannelName): self
    {
        $this->channelReceivingStrategy = new Definition(CustomReceivingStrategy::class, [
            Reference::to(MessagingEntrypoint::class),
            $requestChannelName
        ]);

        return $this;
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        if (!$builder->has(InternalChannelResolver::class)) {
            $builder->register(
                InternalChannelResolver::class,
                new Definition(
                    InternalChannelResolver::class,
                    [
                        Reference::to(ChannelResolver::class),
                        array_map(
                            fn(MessageChannelBuilder $channelBuilder, $key) => ['channel' => $channelBuilder->compile($builder), 'name' => is_int($key) ? $channelBuilder->getMessageChannelName() : $key],
                            $this->internalMessageChannels,
                            array_keys($this->internalMessageChannels)
                        )
                    ]
                )
            );
        }

        return new Definition(
            DynamicMessageChannel::class,
            [
                $this->thisMessageChannelName,
                Reference::to(InternalChannelResolver::class),
                $this->channelSendingStrategy,
                $this->channelReceivingStrategy,
                Reference::to(LoggingGateway::class),
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