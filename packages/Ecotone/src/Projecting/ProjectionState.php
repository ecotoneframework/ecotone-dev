<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

class ProjectionState
{
    public function __construct(
        public readonly string $projectionName,
        public readonly ?string $partitionKey,
        public readonly ?string $lastPosition = null,
        public readonly mixed $userState = null,
    ) {
    }

    public function withLastPosition(string $lastPosition): self
    {
        return new self($this->projectionName, $this->partitionKey, $lastPosition);
    }

    public function withUserState(mixed $userState): self
    {
        return new self($this->projectionName, $this->partitionKey, $this->lastPosition, $userState);
    }
}