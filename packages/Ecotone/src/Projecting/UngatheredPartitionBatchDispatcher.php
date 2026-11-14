<?php

declare(strict_types=1);

namespace Ecotone\Projecting;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;

/**
 * licence Apache-2.0
 */
final class UngatheredPartitionBatchDispatcher implements PartitionBatchDispatcher
{
    public function __construct(private MessagingEntrypointService $messagingEntrypoint)
    {
    }

    public function dispatch(string $projectionName, string $operation, ?string $asyncChannelName, Message $prepare, array $batches): void
    {
        foreach ($batches as $headers) {
            $this->send(projectionName: $projectionName, headers: $headers, asyncChannelName: $asyncChannelName);
        }
    }

    /**
     * @param array<string, mixed> $headers
     */
    public function send(string $projectionName, array $headers, ?string $asyncChannelName): void
    {
        if ($asyncChannelName !== null) {
            $this->messagingEntrypoint->sendWithHeaders($projectionName, $headers, $asyncChannelName, PartitionBatchExecutorHandler::PARTITION_BATCH_EXECUTOR_CHANNEL);

            return;
        }
        $this->messagingEntrypoint->sendWithHeaders($projectionName, $headers, PartitionBatchExecutorHandler::PARTITION_BATCH_EXECUTOR_CHANNEL);
    }
}
