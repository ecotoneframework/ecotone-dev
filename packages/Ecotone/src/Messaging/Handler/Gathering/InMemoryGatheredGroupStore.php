<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Handler\Gathering;

use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Messaging\Message;
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
 *
 */
final class InMemoryGatheredGroupStore implements GatheredGroupStore
{
    private array $groups = [];

    public function __construct(private EcotoneClockInterface $clock, private MessageBuilder $messageBuilder)
    {
    }

    public function openGroup(string $groupId, string $gatherChannelName, int $size, Message $original, ?int $deadline, callable $whenOpened): void
    {
        if (isset($this->groups[$groupId])) {
            return;
        }
        $this->groups[$groupId] = [
            'original' => $this->messageBuilder->fromMessage($original)->setPayload(unserialize(serialize($original->getPayload())))->build(),
            'parts' => [],
            'version' => 0,
            'state' => 'open',
            'latePartCount' => 0,
            'closedAt' => null,
            'deadline' => $deadline,
            'groupId' => $groupId,
            'gatherChannelName' => $gatherChannelName,
            'size' => $size,
            'accountedPartNumbers' => [],
            'missingPartNumbers' => $size === 0 ? [] : range(1, $size),
        ];
        try {
            $whenOpened();
        } catch (Throwable $failure) {
            unset($this->groups[$groupId]);
            throw $failure;
        }
    }

    public function completePart(string $groupId, int $partNumber, Message $report, callable $whenComplete): bool
    {
        $previous = $this->groups[$groupId] ?? throw GatheredGroupNotFound::forId($groupId);
        if ($previous['state'] !== 'open') {
            if ($previous['size'] > 0 && ! isset($previous['parts'][$partNumber])) {
                $this->groups[$groupId]['latePartCount']++;
                $this->groups[$groupId]['version']++;
            }

            return true;
        }
        if (isset($previous['parts'][$partNumber])) {
            return true;
        }
        if ($this->groups[$groupId]['version'] !== $previous['version']) {
            return false;
        }
        $this->groups[$groupId]['version']++;
        if ($previous['size'] > 0) {
            $this->groups[$groupId]['parts'][$partNumber] = $report;
            $this->groups[$groupId]['accountedPartNumbers'][] = $partNumber;
            sort($this->groups[$groupId]['accountedPartNumbers'], SORT_NUMERIC);
            $this->groups[$groupId]['missingPartNumbers'] = array_values(array_diff($previous['missingPartNumbers'], [$partNumber]));
        }
        if (count($this->groups[$groupId]['parts']) !== $previous['size']) {
            return true;
        }
        $this->groups[$groupId]['state'] = 'released';
        $this->groups[$groupId]['closedAt'] = $this->clock->now()->unixTime()->inMilliseconds();
        try {
            $whenComplete($previous['original'], $this->groups[$groupId]['parts'], $previous['size']);
        } catch (Throwable $failure) {
            $this->groups[$groupId] = $previous;
            throw $failure;
        }
        return true;
    }

    public function gatherChannelNameFor(string $groupId): string
    {
        $group = $this->groups[$groupId] ?? throw GatheredGroupNotFound::forId($groupId);

        return $group['gatherChannelName'];
    }

    public function releaseGroup(string $groupId, callable $whenReleased): bool
    {
        $previous = $this->groups[$groupId] ?? null;
        if ($previous === null || $previous['state'] !== 'open') {
            return true;
        }
        if ($this->groups[$groupId]['version'] !== $previous['version']) {
            return false;
        }
        $this->groups[$groupId]['version']++;
        $this->groups[$groupId]['state'] = 'released';
        $this->groups[$groupId]['closedAt'] = $this->clock->now()->unixTime()->inMilliseconds();
        try {
            $whenReleased($previous['original'], $previous['parts'], $previous['size']);
        } catch (Throwable $failure) {
            $this->groups[$groupId] = $previous;
            throw $failure;
        }
        return true;
    }

    public function discard(string $groupId): void
    {
        $group = $this->groups[$groupId] ?? throw GatheredGroupNotFound::forId($groupId);
        if ($group['state'] !== 'open') {
            return;
        }
        $this->groups[$groupId]['version']++;
        $this->groups[$groupId]['state'] = 'discarded';
        $this->groups[$groupId]['closedAt'] = $this->clock->now()->unixTime()->inMilliseconds();
    }

    public function cleanupClosedGroups(int $closedAtOrBefore): void
    {
        $this->groups = array_filter($this->groups, static fn (array $group): bool => $group['state'] === 'open' || $group['closedAt'] > $closedAtOrBefore);
    }

    public function listClosedGroups(string $gatherChannelName): array
    {
        return array_values(array_map(static fn (array $group): array => array_diff_key($group, ['original' => true, 'parts' => true, 'version' => true]), array_filter($this->groups, static fn (array $group): bool => $group['gatherChannelName'] === $gatherChannelName && $group['state'] !== 'open')));
    }

    public function listOpenGroups(string $gatherChannelName): array
    {
        return array_values(array_map(static fn (array $group): array => array_diff_key($group, ['original' => true, 'parts' => true, 'version' => true, 'state' => true]), array_filter($this->groups, static fn (array $group): bool => $group['gatherChannelName'] === $gatherChannelName && $group['state'] === 'open')));
    }
}
