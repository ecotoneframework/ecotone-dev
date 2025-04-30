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
        public readonly ?string $lastPosition = null
    ) {
    }

    public function withLastPosition(string $lastPosition): self
    {
        return new self($this->projectionName, $this->partitionKey, $lastPosition);
    }
}