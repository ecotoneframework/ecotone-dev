<?php

namespace Ecotone\Api\Dbal\ExtensionObject;

use Ecotone\Dbal\DbalInboundChannelAdapterBuilder;
use Ecotone\Dbal\DbalOutboundChannelAdapterBuilder;
use Ecotone\Enqueue\EnqueueMessageChannelBuilder;

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

    public static function create(string $channelName, string $connectionReferenceName = DbalConnectionReference::DEFAULT): self
    {
        return new self($channelName, $connectionReferenceName);
    }

    /**
     * @link https://docs.ecotone.tech/modules/dbal-support#high-throughput-publishing-enterprise
     */
    public function withHighThroughputPublishing(): static
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
