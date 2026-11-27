<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use RuntimeException;

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
final class PartitionHeldInDeadLetter extends RuntimeException
{
    public static function since(DeadLetterPartition $partition, string $holdingDeadLetterId): self
    {
        return new self(
            "Partition key `{$partition->partitionKey}` of `{$partition->trackingName}` is held in the dead letter since message `{$holdingDeadLetterId}` failed, so this message follows it there without being handled. "
            . "Replay any message of the partition key to handle them all in the order they failed, with DeadLetterGateway::replay('{$holdingDeadLetterId}') or the ecotone:deadletter:replay console command; replay only this message, out of that order, with ignorePartitionOrder: true or --ignorePartitionOrder; or delete the messages of the partition key from the dead letter to let new ones through."
        );
    }
}
