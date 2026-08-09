<?php

namespace Ecotone\Dbal;

use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Enqueue\Dbal\DbalConnectionFactory;

/**
 * licence Apache-2.0
 */
class DbalBackedMessageChannelBuilder extends EnqueueMessageChannelBuilder
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

    /**
     * Coalesces published Messages into a single multi row insert.
     * Non blocking confirmation is not offered here, as the insert blocks until the database confirms it.
     */
    public function withHighThroughputPublishing(): self
    {
        $this->getDbalOutboundChannelAdapter()->withBatchPublishing();

        return $this;
    }

    protected function supportsBatchMessages(): bool
    {
        return $this->getDbalOutboundChannelAdapter()->isBatchPublishingEnabled();
    }

    private function getDbalOutboundChannelAdapter(): DbalOutboundChannelAdapterBuilder
    {
        return $this->outboundChannelAdapter;
    }
}
