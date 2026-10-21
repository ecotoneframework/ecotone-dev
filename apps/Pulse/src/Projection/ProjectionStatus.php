<?php

declare(strict_types=1);

namespace App\Projection;

final readonly class ProjectionStatus
{
    public function __construct(
        public string $name,
        public string $mode,
        public bool $partitioned,
        public ?string $initialization,
        public string $gap,
        public bool $behind,
        public ProjectionState $state,
        public ?int $changedSecondsAgo,
        public ?string $unavailable,
    ) {
    }

    public function changedAgo(): ?string
    {
        return match (true) {
            $this->changedSecondsAgo === null => null,
            $this->changedSecondsAgo < 60 => $this->changedSecondsAgo . 's ago',
            $this->changedSecondsAgo < 3600 => intdiv($this->changedSecondsAgo, 60) . 'm ago',
            default => intdiv($this->changedSecondsAgo, 3600) . 'h ago',
        };
    }
}
