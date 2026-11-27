<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Ecotone\Api\Messaging\Message;
use Ecotone\Dbal\Recoverability\DeadLetterStore;

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
final class InMemoryPartitionKeyDeadLetterStore implements PartitionKeyDeadLetterStore
{
    public function __construct(
        private DeadLetterStore $deadLetterStore,
        private InMemoryHeldPartitions $heldPartitions,
    ) {
    }

    public function storeUnderPartitionKey(Message $message, DeadLetterPartition $partition, ?int $position): string
    {
        $deadLetterId = $this->deadLetterStore->store($message);
        if ($position === null) {
            $this->heldPartitions->hold($partition, $deadLetterId);
        } else {
            $this->heldPartitions->holdAtPosition(partition: $partition, deadLetterId: $deadLetterId, position: $position);
        }

        return $deadLetterId;
    }

    public function store(Message $message): string
    {
        return $this->deadLetterStore->store($message);
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
        $this->heldPartitions->forget($messageId);
        $this->deadLetterStore->delete($messageId);
    }

    public function deleteAll(): void
    {
        $this->deadLetterStore->deleteAll();
        $this->heldPartitions->forgetAll();
    }
}
