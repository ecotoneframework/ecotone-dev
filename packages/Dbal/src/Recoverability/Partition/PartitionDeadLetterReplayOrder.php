<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Recoverability\DeadLetterReplayOrder;
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

            return $partition === null || $this->heldPartitions->oldestOf($partition) === $deadLetterId;
        }));
    }

    public function releasing(string $deadLetterId, Message $message): Message
    {
        $position = $this->heldPartitions->positionOf($deadLetterId);
        if ($position === null) {
            return $message;
        }

        return $this->messageBuilder->fromMessage($message)
            ->setHeader(MessageHeaders::DEAD_LETTER_PARTITION_RELEASE, (string) $position)
            ->build();
    }
}
