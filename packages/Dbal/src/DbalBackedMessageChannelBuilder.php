<?php

namespace Ecotone\Dbal;

use Ecotone\Enqueue\EnqueueMessageChannelWithSerializationBuilder;
use Enqueue\Dbal\DbalConnectionFactory;

class DbalBackedMessageChannelBuilder extends EnqueueMessageChannelWithSerializationBuilder
{
    private function __construct(string $channelName, string $connectionReferenceName)
    {
        parent::__construct(
            DbalInboundChannelAdapterBuilder::createWith(
                $channelName,
                $channelName,
                null,
                $connectionReferenceName
            ),
            DbalOutboundChannelAdapterBuilder::create(
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
