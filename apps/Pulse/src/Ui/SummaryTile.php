<?php

declare(strict_types=1);

namespace App\Ui;

final readonly class SummaryTile
{
    public function __construct(
        public string $kind,
        public string $label,
        public string $count,
        public string $noun,
        public string $state,
        public Status $status,
        public ?string $detail,
        public string $href,
        public ?string $concept,
    ) {
    }

    public function phrase(): string
    {
        return match ($this->count) {
            '0' => 'no ' . $this->noun,
            'Off' => $this->noun . ' off',
            default => $this->count . ' ' . $this->noun . ' · ' . $this->state,
        };
    }
}
