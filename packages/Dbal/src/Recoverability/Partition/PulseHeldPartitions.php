<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseSnapshotSection;

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
final class PulseHeldPartitions implements PulseSnapshotSection
{
    public const SECTION_NAME = 'deadLetterPartitions';
    private const LISTED_LIMIT = 100;

    public function __construct(private HeldPartitions $heldPartitions)
    {
    }

    /**
     * @return array{total: int, partitions: list<array{trackingName: string, partitionKey: string, waitingMessages: int, releasedMessages: int, oldestMessageId: string, messageIds: list<string>}>}
     */
    public function snapshot(): array
    {
        $summary = $this->heldPartitions->summary();

        return [
            'total' => count($summary),
            'partitions' => array_map(
                fn (array $held): array => [
                    'trackingName' => $held['trackingName'],
                    'partitionKey' => $held['partitionKey'],
                    'waitingMessages' => $held['waiting'],
                    'releasedMessages' => $held['released'],
                    'oldestMessageId' => $held['oldestDeadLetterId'],
                    'messageIds' => $this->heldPartitions->waitingOf(new DeadLetterPartition(trackingName: $held['trackingName'], partitionKey: $held['partitionKey'])),
                ],
                array_slice($summary, 0, self::LISTED_LIMIT),
            ),
        ];
    }

    public function revision(): ?string
    {
        return json_encode($this->heldPartitions->summary(), JSON_THROW_ON_ERROR);
    }
}
