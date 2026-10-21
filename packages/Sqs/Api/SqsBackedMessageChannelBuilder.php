<?php

declare(strict_types=1);

namespace Ecotone\Api\Sqs;

use Ecotone\Enqueue\EnqueueMessageChannelBuilder;
use Ecotone\Messaging\Channel\MessageChannelBuilderOnSameConnection;
use Ecotone\Sqs\SqsInboundChannelAdapterBuilder;
use Ecotone\Sqs\SqsOutboundChannelAdapterBuilder;

/**
 * licence Apache-2.0
 */
final class SqsBackedMessageChannelBuilder extends EnqueueMessageChannelBuilder implements MessageChannelBuilderOnSameConnection
{
    private function __construct(string $channelName, public readonly string $connectionReferenceName)
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

    public static function create(string $channelName, string $connectionReferenceName = SqsConnectionReference::DEFAULT): self
    {
        return new self($channelName, $connectionReferenceName);
    }

    /**
     * @link https://docs.ecotone.tech/modules/amazon-sqs-support#high-throughput-publishing-enterprise
     */
    public function withHighThroughputPublishing(bool $batchPublishing = true, bool $nonBlockingConfirmation = true, ?int $confirmationTimeoutInMilliseconds = null): self
    {
        $this->getSqsOutboundChannelAdapter()->withHighThroughputPublishing($batchPublishing, $nonBlockingConfirmation, $confirmationTimeoutInMilliseconds);

        return $this;
    }

    protected function supportsBatchMessages(): bool
    {
        return $this->getSqsOutboundChannelAdapter()->isBatchPublishingEnabled();
    }

    public function forChannelOnSameConnection(string $channelName): self
    {
        return self::create($channelName, $this->connectionReferenceName);
    }

    private function getSqsOutboundChannelAdapter(): SqsOutboundChannelAdapterBuilder
    {
        return $this->outboundChannelAdapter;
    }
}
