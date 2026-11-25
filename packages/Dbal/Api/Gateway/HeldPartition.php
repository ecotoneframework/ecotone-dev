<?php

declare(strict_types=1);

namespace Ecotone\Api\Dbal\Gateway;

/**
 * licence Enterprise
 *
 * Use requires an Ecotone Enterprise licence (see LICENSE-ENTERPRISE; trial: https://ecotone.tech/pricing).
 */
final class HeldPartition
{
    public function __construct(
        public readonly string $trackingName,
        public readonly string $partitionKey,
        public readonly int $waitingMessages,
        public readonly string $oldestMessageId,
    ) {
    }
}
