<?php

declare(strict_types=1);

namespace App\Ui;

use App\Home\Severity;
use App\Home\Tile;
use App\Snapshot\ServiceStatus;

final readonly class ServiceLine
{
    /**
     * @param list<ProblemCard> $cards
     * @param list<SummaryTile> $facts
     */
    public function __construct(public Tile $tile, public array $cards, public array $facts)
    {
    }

    public function connectionStatus(): Status
    {
        return match ($this->tile->scope->status) {
            ServiceStatus::Connected => Status::Ok,
            ServiceStatus::Waiting => Status::Info,
            ServiceStatus::Unreachable, ServiceStatus::ChannelMissing => Status::Critical,
        };
    }

    public function connectionLabel(): string
    {
        return match ($this->tile->scope->status) {
            ServiceStatus::Connected => 'Connected',
            ServiceStatus::Waiting => 'Waiting',
            ServiceStatus::Unreachable => 'Unreachable',
            ServiceStatus::ChannelMissing => 'Channel missing',
        };
    }

    public function status(): Status
    {
        return Status::worstOf($this->connectionStatus(), ...array_map(static fn (ProblemCard $card): Severity => $card->severity, $this->cards));
    }

    public function problemsSummary(): string
    {
        $counts = [];
        foreach ($this->cards as $card) {
            $counts[$card->severity->value] = ($counts[$card->severity->value] ?? 0) + 1;
        }

        return sprintf(
            '%d %s · %s',
            count($this->cards),
            count($this->cards) === 1 ? 'problem' : 'problems',
            implode(', ', array_map(static fn (string $severity, int $count): string => $count . ' ' . $severity, array_keys($counts), $counts)),
        );
    }
}
