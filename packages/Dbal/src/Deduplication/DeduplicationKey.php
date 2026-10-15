<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Deduplication;

/**
 * licence Apache-2.0
 */
final class DeduplicationKey
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $consumerEndpointId,
        public readonly string $routingSlip,
    ) {
    }
}
