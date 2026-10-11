<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Deduplication;

use function array_filter;

use Ecotone\Api\Messaging\ConcurrencyException;

use function json_encode;

/**
 * licence Apache-2.0
 */
final class InMemoryHandledMessages implements HandledMessages
{
    /**
     * @var array<string, int>
     */
    private array $handledAt = [];

    public function wasHandled(DeduplicationKey $key): bool
    {
        return isset($this->handledAt[$this->keyOf($key)]);
    }

    public function recordsBeforeHandling(): bool
    {
        return false;
    }

    public function recordHandled(DeduplicationKey $key, int $handledAtInMilliseconds): void
    {
        if ($this->wasHandled($key)) {
            throw ConcurrencyException::forHandledMessage($key->messageId, $key->consumerEndpointId, $key->routingSlip);
        }

        $this->handledAt[$this->keyOf($key)] = $handledAtInMilliseconds;
    }

    public function removeHandledBefore(int $thresholdInMilliseconds): void
    {
        $this->handledAt = array_filter($this->handledAt, fn (int $handledAt): bool => $handledAt > $thresholdInMilliseconds);
    }

    private function keyOf(DeduplicationKey $key): string
    {
        return json_encode([$key->messageId, $key->consumerEndpointId, $key->routingSlip], JSON_THROW_ON_ERROR);
    }
}
