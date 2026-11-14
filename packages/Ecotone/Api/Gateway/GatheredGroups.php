<?php

declare(strict_types=1);

namespace Ecotone\Api\Gateway;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
interface GatheredGroups
{
    public function requestRelease(string $groupId): void;

    public function discard(string $groupId): void;

    public function cleanupClosedGroups(): void;

    /** @return array<array{groupId: string, gatherChannelName: string, size: int, state: string, deadline: ?int, closedAt: ?int, latePartCount: int, accountedPartNumbers: array<int>, missingPartNumbers: array<int>}> */
    public function listClosedGroups(string $gatherChannelName): array;

    /** @return array<array{groupId: string, gatherChannelName: string, size: int, state: string, deadline: ?int, closedAt: ?int, latePartCount: int, accountedPartNumbers: array<int>, missingPartNumbers: array<int>}> */
    public function listOpenGroups(string $gatherChannelName): array;
}
