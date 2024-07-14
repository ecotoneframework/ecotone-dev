<?php

declare(strict_types=1);

namespace Ecotone\Sqs;

use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Enqueue\Sqs\SqsConnectionFactory;

/**
 * licence Apache-2.0
 */
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

    public static function create(string $channelName, string $connectionReferenceName = SqsConnectionFactory::class): self
    {
        return new self($channelName, $connectionReferenceName);
    }
}
