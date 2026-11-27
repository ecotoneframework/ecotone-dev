<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Recoverability\DeadLetterStore;
use Ecotone\Messaging\Support\MessageBuilder;
use Throwable;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class PartitionDeadLetterStore implements DeadLetterStore
{
    public function __construct(
        private PartitionKeyDeadLetterStore $deadLetterStore,
        private PartitionedHandlers $partitionedHandlers,
        private MessageBuilder $messageBuilder,
    ) {
    }

    public function store(Message $message): string
    {
        $partition = $this->partitionToHoldFor($message);
        if ($partition === null) {
            return $this->deadLetterStore->store($message);
        }

        if (! $message->getHeaders()->containsKey(MessageHeaders::DEAD_LETTER_PARTITION_RELEASE)) {
            return $this->deadLetterStore->storeUnderPartitionKey(message: $message, partition: $partition, position: null);
        }

        return $this->deadLetterStore->storeUnderPartitionKey(
            message: $this->messageBuilder->fromMessage($message)->removeHeader(MessageHeaders::DEAD_LETTER_PARTITION_RELEASE)->build(),
            partition: $partition,
            position: (int) $message->getHeaders()->get(MessageHeaders::DEAD_LETTER_PARTITION_RELEASE),
        );
    }

    private function partitionToHoldFor(Message $message): ?DeadLetterPartition
    {
        try {
            return $this->partitionedHandlers->partitionOf($message);
        } catch (Throwable) {
            return null;
        }
    }

    public function list(int $limit, int $offset): array
    {
        return $this->deadLetterStore->list($limit, $offset);
    }

    public function listMessages(int $limit, int $offset): array
    {
        return $this->deadLetterStore->listMessages($limit, $offset);
    }

    public function find(string $messageId): ?Message
    {
        return $this->deadLetterStore->find($messageId);
    }

    public function count(): int
    {
        return $this->deadLetterStore->count();
    }

    public function delete(string $messageId): void
    {
        $this->deadLetterStore->delete($messageId);
    }

    public function deleteAll(): void
    {
        $this->deadLetterStore->deleteAll();
    }
}
