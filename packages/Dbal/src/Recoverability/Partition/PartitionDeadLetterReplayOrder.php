<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Recoverability\DeadLetterReplayOrder;
use Ecotone\Messaging\Support\InvalidArgumentException;
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
final class PartitionDeadLetterReplayOrder implements DeadLetterReplayOrder
{
    public function __construct(
        private HeldPartitions $heldPartitions,
        private MessageBuilder $messageBuilder,
    ) {
    }

    public function replayableOf(array $deadLetterIds): array
    {
        return array_values(array_filter($deadLetterIds, function (string $deadLetterId): bool {
            $partition = $this->heldPartitions->partitionOf($deadLetterId);

            return $partition === null || ($this->heldPartitions->oldestWaitingOf($partition) === $deadLetterId && $this->heldPartitions->releasedOf($partition) === []);
        }));
    }

    public function releasing(string $deadLetterId, Message $message): Message
    {
        $partition = $this->heldPartitions->partitionOf($deadLetterId);
        if ($partition === null) {
            return $message;
        }

        if (! $this->heldPartitions->isReleased($deadLetterId)) {
            $replayed = $this->heldPartitions->releasedOf($partition);
            if ($replayed !== []) {
                throw InvalidArgumentException::create(
                    "Dead letter message `{$deadLetterId}` can not be replayed while message `{$replayed[0]}` of its partition key `{$partition->partitionKey}` of `{$partition->trackingName}` is being replayed, as that would handle them out of order. "
                    . 'Nothing has to be done: it is replayed once the messages before it succeed. '
                    . "When the replayed message will never be handled (its channel was purged), delete the partition key with PartitionDeadLetterGateway::delete('{$partition->trackingName}', '{$partition->partitionKey}') or the ecotone:deadletter:deletePartition console command."
                );
            }
            $oldestWaiting = $this->heldPartitions->oldestWaitingOf($partition);
            if ($oldestWaiting !== $deadLetterId) {
                throw InvalidArgumentException::create(
                    "Dead letter message `{$deadLetterId}` can not be replayed before the older messages of its partition key `{$partition->partitionKey}` of `{$partition->trackingName}`, as that would handle them out of order. "
                    . "Replay the oldest one, `{$oldestWaiting}`, and the rest follow in order once it succeeds: PartitionDeadLetterGateway::replay('{$partition->trackingName}', '{$partition->partitionKey}') or the ecotone:deadletter:replayPartition console command do that. "
                    . 'To handle this message first anyway, delete the older messages of the partition key from the dead letter.'
                );
            }
            $this->heldPartitions->release($deadLetterId);
        }

        return $this->messageBuilder->fromMessage($message)
            ->setHeader(MessageHeaders::DEAD_LETTER_PARTITION_RELEASE, $deadLetterId)
            ->build();
    }
}
