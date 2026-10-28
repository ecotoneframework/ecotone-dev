<?php

declare(strict_types=1);

namespace App\Sales;

use App\Catalogue\Concert;
use App\Catalogue\ConcertStatus;
use App\Catalogue\Seat;
use App\Catalogue\Tier;

final readonly class ConcertForSale
{
    /**
     * @param array{concertId: string, artist: string, status: string, standardPriceCents: int, vipPriceCents: int, seats: list<array{seatId: string, seatCode: string, tier: string}>} $detail
     */
    private function __construct(private array $detail)
    {
    }

    public static function of(?Concert $concert): ?self
    {
        return $concert === null ? null : new self($concert->detail());
    }

    public function isOnSale(): bool
    {
        return $this->detail['status'] === ConcertStatus::ON_SALE->value;
    }

    public function seat(string $seatId): ?Seat
    {
        foreach ($this->detail['seats'] as $seat) {
            if ($seat['seatId'] === $seatId) {
                return new Seat(seatId: $seat['seatId'], seatCode: $seat['seatCode'], tier: Tier::from($seat['tier']));
            }
        }

        return null;
    }

    public function seatCount(): int
    {
        return count($this->detail['seats']);
    }

    public function priceOf(Tier $tier): int
    {
        return match ($tier) {
            Tier::STANDARD => $this->detail['standardPriceCents'],
            Tier::VIP => $this->detail['vipPriceCents'],
        };
    }

    public function title(): string
    {
        return sprintf('the concert of %s', $this->detail['artist']);
    }

    public function seatRange(): string
    {
        $seats = $this->detail['seats'];

        return $seats === [] ? 'none' : sprintf('%s to %s', $seats[0]['seatCode'], $seats[array_key_last($seats)]['seatCode']);
    }

    public function statusInWords(): string
    {
        return str_replace('_', ' ', $this->detail['status']);
    }
}
