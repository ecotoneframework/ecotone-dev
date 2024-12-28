<?php

declare(strict_types=1);

namespace Ecotone\EventSourcingV2\EventStore;

use Stringable;

readonly class StreamEventId
{
    public function __construct(
        public string|Stringable $streamId,
        public ?int    $version = null,
    ) {
    }

    public function withVersion(int $version): self
    {
        return new self($this->streamId, $version);
    }

    public function withoutVersion(): self
    {
        return new self($this->streamId);
    }

    public function equals(self $eventStreamId): bool
    {
        return $this->streamId === $eventStreamId->streamId && $this->version === $eventStreamId->version;
    }
}