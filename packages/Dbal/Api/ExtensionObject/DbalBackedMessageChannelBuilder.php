<?php

namespace Ecotone\Api\Dbal\ExtensionObject;

use Ecotone\Dbal\DbalInboundChannelAdapterBuilder;
use Ecotone\Dbal\DbalOutboundChannelAdapterBuilder;
use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Ecotone\Messaging\Channel\MessageChannelBuilderOnSameConnection;

/**
 * licence Apache-2.0
 */
class DbalBackedMessageChannelBuilder extends EnqueueMessageChannelBuilder implements MessageChannelBuilderOnSameConnection
{
    private function __construct(string $channelName, public readonly string $connectionReferenceName)
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

    public function forSendOnlyChannelOnSameConnection(string $channelName): self
    {
        return self::create($channelName, $this->connectionReferenceName);
    }

    private function getDbalOutboundChannelAdapter(): DbalOutboundChannelAdapterBuilder
    {
        return $this->outboundChannelAdapter;
    }
}
