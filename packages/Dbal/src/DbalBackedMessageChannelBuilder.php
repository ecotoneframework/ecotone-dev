<?php

namespace Ecotone\Dbal;

use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Ecotone\Messaging\Channel\BatchForwardingSourceChannel;
use Enqueue\Dbal\DbalConnectionFactory;

/**
 * licence Apache-2.0
 */
class DbalBackedMessageChannelBuilder extends EnqueueMessageChannelBuilder implements BatchForwardingSourceChannel
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

    public function withHighThroughputPublishing(bool $enabled = true): self
    {
        $this->getDbalOutboundChannelAdapter()->withAsyncPublishing($enabled);

        return $this;
    }

    protected function supportsBatchMessages(): bool
    {
        return $this->getDbalOutboundChannelAdapter()->isAsyncPublishingEnabled();
    }

    private function getDbalOutboundChannelAdapter(): DbalOutboundChannelAdapterBuilder
    {
        return $this->outboundChannelAdapter;
    }
}
