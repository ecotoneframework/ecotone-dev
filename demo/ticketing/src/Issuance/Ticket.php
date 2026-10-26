<?php

declare(strict_types=1);

namespace App\Issuance;

use App\Catalogue\Tier;

final readonly class Ticket
{
    public function __construct(
        public string $ticketNumber,
        public string $seatId,
        public Tier $tier,
        public string $entryCode,
        public bool $vipLoungePass,
    ) {
    }

    public function seatCode(): string
    {
        return substr($this->seatId, strrpos($this->seatId, '/') + 1);
    }

    public function withVipLoungePass(): self
    {
        return new self(...[...get_object_vars($this), 'vipLoungePass' => $this->tier === Tier::VIP]);
    }
}
