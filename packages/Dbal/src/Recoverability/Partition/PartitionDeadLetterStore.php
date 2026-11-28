<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Ecotone\Api\Messaging\ErrorContext;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Recoverability\DeadLetterStore;
use Ecotone\Messaging\Support\MessageBuilder;

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
        private HeldPartitions $heldPartitions,
        private MessageBuilder $messageBuilder,
    ) {
    }

    public function store(Message $message): string
    {
        $partition = $this->partitionToHoldFor($message);
        if ($partition === null) {
            return $this->deadLetterStore->store($message);
        }

        foreach ([MessageHeaders::DEAD_LETTER_PARTITION_RELEASE, MessageHeaders::DEAD_LETTER_PARTITION_ORDER_IGNORED] as $positionHeader) {
            if ($message->getHeaders()->containsKey($positionHeader)) {
                return $this->deadLetterStore->storeUnderPartitionKey(
                    message: $this->messageBuilder->fromMessage($message)->removeHeader($positionHeader)->build(),
                    partition: $partition,
                    position: (int) $message->getHeaders()->get($positionHeader),
                );
            }
        }

        return $this->deadLetterStore->storeUnderPartitionKey(message: $message, partition: $partition, position: null);
    }

    private function partitionToHoldFor(Message $message): ?DeadLetterPartition
    {
        try {
            return $this->partitionedHandlers->partitionOf($message);
        } catch (PartitionKeyNotCalculated) {
            return null;
        }
    }

    public function list(int $limit, int $offset): array
    {
        return array_map(
            static fn (Message $message): ErrorContext => ErrorContext::fromHeaders($message->getHeaders()->headers()),
            $this->listMessages($limit, $offset),
        );
    }

    public function listMessages(int $limit, int $offset): array
    {
        $messagesByDeadLetterId = [];
        foreach ($this->deadLetterStore->listMessages($limit, $offset) as $message) {
            $messagesByDeadLetterId[(string) $message->getHeaders()->getMessageId()] = $message;
        }

        return $this->namingTheirPartitionKeys($messagesByDeadLetterId);
    }

    public function find(string $messageId): ?Message
    {
        $message = $this->deadLetterStore->find($messageId);

        return $message === null ? null : $this->namingTheirPartitionKeys([$messageId => $message])[0];
    }

    /**
     * @param array<string, Message> $messagesByDeadLetterId
     * @return list<Message>
     */
    private function namingTheirPartitionKeys(array $messagesByDeadLetterId): array
    {
        $heldAmong = $this->heldPartitions->heldAmong(array_map('strval', array_keys($messagesByDeadLetterId)));
        $messages = [];
        foreach ($messagesByDeadLetterId as $deadLetterId => $message) {
            $held = $heldAmong[(string) $deadLetterId] ?? null;
            $messages[] = $held === null ? $message : $this->messageBuilder->fromMessage($message)
                ->setHeader(MessageHeaders::DEAD_LETTER_PARTITION_TRACKING_NAME, $held['trackingName'])
                ->setHeader(MessageHeaders::DEAD_LETTER_PARTITION_KEY, $held['partitionKey'])
                ->setHeader(MessageHeaders::DEAD_LETTER_PARTITION_POSITION, $held['position'])
                ->build();
        }

        return $messages;
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
