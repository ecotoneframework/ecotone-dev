<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Ecotone\Api\Dbal\Gateway\HeldPartition;
use Ecotone\Dbal\Recoverability\DeadLetterHandler;
use Ecotone\Messaging\Support\InvalidArgumentException;

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
final class PartitionDeadLetterActions
{
    public const TRACKING_NAME_HEADER = 'ecotone_dbal_deadletter_partition_tracking_name';
    public const PARTITION_KEY_HEADER = 'ecotone_dbal_deadletter_partition_key';

    public const LIST_CHANNEL = 'ecotone.dbal.deadletter.partition.list';
    public const REPLAY_CHANNEL = 'ecotone.dbal.deadletter.partition.replay';
    public const DELETE_CHANNEL = 'ecotone.dbal.deadletter.partition.delete';

    public function __construct(
        private HeldPartitions $heldPartitions,
        private PartitionReplay $partitionReplay,
        private DeadLetterHandler $deadLetterHandler,
    ) {
    }

    /**
     * @return list<HeldPartition>
     */
    public function list(): array
    {
        return array_map(
            static fn (array $held): HeldPartition => new HeldPartition(
                trackingName: $held['trackingName'],
                partitionKey: $held['partitionKey'],
                waitingMessages: $held['waiting'],
                releasedMessages: $held['released'],
                oldestMessageId: $held['oldestDeadLetterId'],
            ),
            $this->heldPartitions->summary(),
        );
    }

    public function replay(string $trackingName, string $partitionKey): void
    {
        $partition = new DeadLetterPartition(trackingName: $trackingName, partitionKey: $partitionKey);
        if ($this->partitionReplay->continueWith($partition)) {
            return;
        }

        if ($this->partitionReplay->isReplaying($partition)) {
            throw InvalidArgumentException::create(
                "Partition key `{$partitionKey}` of `{$trackingName}` is being replayed already: one of its messages was released and is not handled yet, and the rest follow in order once it succeeds, so nothing has to be done. "
                . 'When that message will never be handled (its channel was purged), delete the partition key with PartitionDeadLetterGateway::delete() or the ecotone:deadletter:deletePartition console command.'
            );
        }

        throw InvalidArgumentException::create(
            "The dead letter holds no message for partition key `{$partitionKey}` of `{$trackingName}`: its messages were replayed or deleted already, or the tracking name and the partition key do not name a held one. "
            . 'List the held partition keys with PartitionDeadLetterGateway::list() or the ecotone:deadletter:listPartitions console command.'
        );
    }

    public function delete(string $trackingName, string $partitionKey): void
    {
        $partition = new DeadLetterPartition(trackingName: $trackingName, partitionKey: $partitionKey);
        foreach ($this->heldPartitions->waitingOf($partition) as $deadLetterId) {
            $this->deadLetterHandler->delete($deadLetterId);
        }
        foreach ($this->heldPartitions->releasedOf($partition) as $deadLetterId) {
            $this->heldPartitions->forget($deadLetterId);
        }
    }
}
