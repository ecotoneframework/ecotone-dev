<?php

declare(strict_types=1);

namespace Ecotone\Api\Messaging;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
final class CollectedMessages
{
    /**
     * @param list<mixed> $payloads
     * @param list<array<string, mixed>> $headers
     */
    public function __construct(
        public readonly array $payloads,
        public readonly array $headers,
        public readonly ?string $groupKey,
        public readonly int $size,
        public readonly CollectedReleaseReason $releaseReason,
    ) {
    }
}
