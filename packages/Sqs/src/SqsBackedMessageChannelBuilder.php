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

    /**
     * @param bool $batchPublishing coalesces published Messages into SQS batch send requests
     * @param bool $nonBlockingConfirmation dispatches send requests without waiting on their responses, which are awaited before the surrounding Command Bus or asynchronous endpoint finishes
     * @param int|null $confirmationTimeoutInMilliseconds how long to await a send response before treating the delivery as failed
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

    private function getSqsOutboundChannelAdapter(): SqsOutboundChannelAdapterBuilder
    {
        return $this->outboundChannelAdapter;
    }
}
