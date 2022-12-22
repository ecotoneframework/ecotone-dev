<?php

declare(strict_types=1);

namespace Test\SqsDemo;

use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Enqueue\Dbal\DbalConnectionFactory;

final class SqsBackedMessageChannelBuilder extends EnqueueMessageChannelBuilder
{
    private function __construct(string $channelName, string $connectionReferenceName)
    {
        parent::__construct(
            SqsInboundChannelAdapterBuilder::createWith(
                $channelName,
                $channelName,
                null,
                $connectionReferenceName
            ),
            SqsOutboundChannelAdapterBuilder::create(
                $channelName,
                $connectionReferenceName
            )
        );
    }

    public static function create(string $channelName, string $connectionReferenceName = DbalConnectionFactory::class): self
    {
        return new self($channelName, $connectionReferenceName);
    }
}