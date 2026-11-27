<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Pulse;

use DateTimeInterface;
use Ecotone\Api\Messaging\Message;
use Ecotone\Dbal\Recoverability\DeadLetterStore;
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
 *
 * @internal
 */
final class PulseDeadLetters implements PulseSnapshotSection
{
    private const RECENT_LIMIT = 100;
    private const HELD_PARTITIONS_LIMIT = 100;

    public function __construct(private DeadLetterStore $store)
    {
    }

    /**
     * @return array{total: int, heldPartitions: array{total: int, partitions: list<array{trackingName: string, partitionKey: string, waitingMessages: int, oldestMessageId: string, messageIds: list<string>}>}, groups: list<array{failureId: string, messageName: string, exceptionClass: string, exceptionFile: string, exceptionLine: int, exceptionMessage: string, count: int, firstFailedAt: string, lastFailedAt: string}>, recent: array<array<string, mixed>>}
     */
    public function snapshot(): array
    {
        $failures = [];
        $recent = [];
        $held = [];
        foreach (PulseDeadLetterScan::newestFirst($this->store) as $entry) {
            $failureId = $entry->failureId();
            $failedAt = $entry->failedAt();
            $heldPartition = $entry->heldPartition();
            if ($heldPartition !== null) {
                $held[$heldPartition['trackingName'] . "\0" . $heldPartition['partitionKey']][] = [$heldPartition['position'], $failedAt->format('U.u'), $entry->messageId(), $heldPartition];
            }
            if (! isset($failures[$failureId])) {
                $failures[$failureId] = [
                    'failureId' => $failureId,
                    'messageName' => $entry->messageName(),
                    'exceptionClass' => $entry->exceptionClass(),
                    'exceptionFile' => $entry->exceptionFile(),
                    'exceptionLine' => $entry->exceptionLine(),
                    'exceptionMessage' => $entry->exceptionMessage(),
                    'count' => 0,
                    'firstFailedAt' => $failedAt,
                    'lastFailedAt' => $failedAt,
                ];
            }
            $failures[$failureId]['count']++;
            $failures[$failureId]['firstFailedAt'] = min($failures[$failureId]['firstFailedAt'], $failedAt);
            $failures[$failureId]['lastFailedAt'] = max($failures[$failureId]['lastFailedAt'], $failedAt);
            if (count($recent) < self::RECENT_LIMIT) {
                $recent[] = $entry->toArray();
            }
        }

        $groups = array_map(
            fn (array $failure): array => [...$failure, 'firstFailedAt' => $failure['firstFailedAt']->format(DateTimeInterface::ATOM), 'lastFailedAt' => $failure['lastFailedAt']->format(DateTimeInterface::ATOM)],
            array_values($failures),
        );
        usort($groups, fn (array $first, array $second): int => [$second['count'], $second['lastFailedAt'], $first['failureId']] <=> [$first['count'], $first['lastFailedAt'], $second['failureId']]);

        return ['total' => $this->store->count(), 'heldPartitions' => self::heldPartitionsOf($held), 'groups' => $groups, 'recent' => $recent];
    }

    /**
     * @param array<string, list<array{int, string, string, array{trackingName: string, partitionKey: string, position: int}}>> $held
     * @return array{total: int, partitions: list<array{trackingName: string, partitionKey: string, waitingMessages: int, oldestMessageId: string, messageIds: list<string>}>}
     */
    private static function heldPartitionsOf(array $held): array
    {
        $partitions = [];
        foreach ($held as $waiting) {
            sort($waiting);
            $partitions[] = [
                'trackingName' => $waiting[0][3]['trackingName'],
                'partitionKey' => $waiting[0][3]['partitionKey'],
                'waitingMessages' => count($waiting),
                'oldestMessageId' => $waiting[0][2],
                'messageIds' => array_column($waiting, 2),
                'oldestFailedAt' => $waiting[0][1],
            ];
        }
        usort($partitions, static fn (array $first, array $second): int => [$first['oldestFailedAt'], $first['trackingName'], $first['partitionKey']] <=> [$second['oldestFailedAt'], $second['trackingName'], $second['partitionKey']]);

        return [
            'total' => count($partitions),
            'partitions' => array_map(
                static fn (array $partition): array => array_diff_key($partition, ['oldestFailedAt' => true]),
                array_slice($partitions, 0, self::HELD_PARTITIONS_LIMIT),
            ),
        ];
    }

    public function revision(): ?string
    {
        $newest = array_map(
            static function (Message $message): string {
                $entry = PulseDeadLetterEntry::from($message);

                return $entry->messageId() . ' ' . $entry->failedAt()->format('U.u') . ' ' . $entry->exceptionClass() . ' ' . $entry->exceptionMessage();
            },
            $this->store->listMessages(self::RECENT_LIMIT, 0),
        );

        return $this->store->count() . "\n" . implode("\n", $newest);
    }
}
