<?php

declare(strict_types=1);

namespace App\Home;

use App\Snapshot\ServiceSnapshot;
use App\Snapshot\ServiceStatus;

final readonly class Tile
{
    /**
     * @param list<Problem> $problems most severe first
     */
    public function __construct(public ServiceSnapshot $scope, public array $problems)
    {
    }

    public function worst(): ?Severity
    {
        return $this->problems[0]->severity ?? null;
    }

    public function isUnreachable(): bool
    {
        return in_array($this->scope->status, [ServiceStatus::Unreachable, ServiceStatus::ChannelMissing], true);
    }

    public function isHealthy(): bool
    {
        return ! in_array($this->worst(), [Severity::Critical, Severity::Warning], true) && $this->scope->status === ServiceStatus::Connected;
    }

    public function isWaiting(): bool
    {
        return $this->scope->status === ServiceStatus::Waiting;
    }

    public function sortKey(): string
    {
        return $this->scope->scope();
    }
}
