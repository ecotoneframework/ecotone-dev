<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Deduplication;

/**
 * licence Apache-2.0
 */
interface HandledMessages
{
    public function wasHandled(DeduplicationKey $key): bool;

    public function recordsBeforeHandling(): bool;

    public function recordHandled(DeduplicationKey $key, int $handledAtInMilliseconds): void;

    public function removeHandledBefore(int $thresholdInMilliseconds): void;
}
