<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Ecotone\Api\Console\ConsoleCommandResultSet;
use Ecotone\Api\Dbal\Gateway\HeldPartition;
use Ecotone\Api\Dbal\Gateway\PartitionDeadLetterGateway;

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
final class PartitionDeadLetterConsoleCommand
{
    public const LIST_COMMAND_NAME = 'ecotone:deadletter:listPartitions';
    public const REPLAY_COMMAND_NAME = 'ecotone:deadletter:replayPartition';
    public const DELETE_COMMAND_NAME = 'ecotone:deadletter:deletePartition';

    public function listPartitions(PartitionDeadLetterGateway $partitionDeadLetterGateway): ConsoleCommandResultSet
    {
        return ConsoleCommandResultSet::create(
            ['Tracking Name', 'Partition Key', 'Waiting Messages', 'Released Messages', 'Oldest Message Id'],
            array_map(
                static fn (HeldPartition $heldPartition): array => [$heldPartition->trackingName, $heldPartition->partitionKey, $heldPartition->waitingMessages, $heldPartition->releasedMessages, $heldPartition->oldestMessageId],
                $partitionDeadLetterGateway->list(),
            ),
        );
    }

    public function replayPartition(PartitionDeadLetterGateway $partitionDeadLetterGateway, string $trackingName, string $partitionKey): void
    {
        $partitionDeadLetterGateway->replay($trackingName, $partitionKey);
    }

    public function deletePartition(PartitionDeadLetterGateway $partitionDeadLetterGateway, string $trackingName, string $partitionKey): void
    {
        $partitionDeadLetterGateway->delete($trackingName, $partitionKey);
    }
}
