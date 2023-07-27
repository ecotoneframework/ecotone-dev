<?php

declare(strict_types=1);

namespace Ecotone\Sqs;

use Ecotone\Enqueue\EnqueueMessageChannelWithSerializationBuilder;
use Enqueue\Sqs\SqsConnectionFactory;

final class SqsBackedMessageChannelBuilder extends EnqueueMessageChannelWithSerializationBuilder
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

    public static function create(string $channelName, string $connectionReferenceName = SqsConnectionFactory::class): self
    {
        return new self($channelName, $connectionReferenceName);
    }
}
