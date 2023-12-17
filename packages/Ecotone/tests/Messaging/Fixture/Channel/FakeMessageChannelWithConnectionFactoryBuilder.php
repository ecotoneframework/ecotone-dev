<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Channel;

use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Test\Ecotone\Messaging\Fixture\Config\FakeConnectionFactory;

final class FakeMessageChannelWithConnectionFactoryBuilder implements MessageChannelBuilder
{
    private function __construct(
        private string $channelName,
        private string $connectionFactoryReferenceName,
        private bool $verifyConnectionOnPoll
    )
    {

    }

    public static function create(
        string $channelName,
        string $connectionFactoryReferenceName = FakeConnectionFactory::class,
        bool $verifyConnectionOnPoll = true
    )
    {
        return new self($channelName, $connectionFactoryReferenceName, $verifyConnectionOnPoll);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            FakeMessageChannelWithConnectionFactory::class,
            [
                $this->channelName,
                Reference::to($this->connectionFactoryReferenceName),
                $this->verifyConnectionOnPoll
            ]
        );
    }

    public function getMessageChannelName(): string
    {
        return $this->channelName;
    }

    public function isPollable(): bool
    {
        return true;
    }
}