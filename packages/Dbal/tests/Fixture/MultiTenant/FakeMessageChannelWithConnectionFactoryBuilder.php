<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant;

use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;

/**
 * licence Apache-2.0
 */
final class FakeMessageChannelWithConnectionFactoryBuilder implements MessageChannelBuilder
{
    private function __construct(
        private string $channelName,
        private string $connectionFactoryReferenceName,
    ) {

    }

    public static function create(
        string $channelName,
        string $connectionFactoryReferenceName = FakeConnectionFactory::class,
    ) {
        return new self($channelName, $connectionFactoryReferenceName);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        return new Definition(
            FakeMessageChannelWithConnectionFactory::class,
            [
                $this->channelName,
                Reference::to($this->connectionFactoryReferenceName),
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

    /**
     * @return string
     */
    public function isStreamingChannel(): bool
    {
        return false;
    }
}
