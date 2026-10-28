<?php

declare(strict_types=1);

namespace App\Sales;

use DateTimeImmutable;
use DateTimeInterface;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\DecisionModel;

#[DecisionModel(tags: ['customerConcert'])]
final class CustomerConcertSeats
{
    public const MAXIMUM_SEATS = 4;

    public static function scopeOf(string $customerId, string $concertId): string
    {
        return $customerId . '/' . $concertId;
    }

    /**
     * @var array<string, array{orderId: string, heldUntil: ?DateTimeImmutable}>
     */
    private array $heldSeats = [];

    /**
     * @var array<string, array{orderId: string, paymentReference: string}>
     */
    private array $soldSeats = [];

    #[Apply]
    public function held(SeatHeld $event): void
    {
        $this->heldSeats[$event->seatId] = ['orderId' => $event->orderId, 'heldUntil' => $event->expiresAt];
    }

    #[Apply]
    public function released(HoldReleased $event): void
    {
        unset($this->heldSeats[$event->seatId]);
    }

    #[Apply]
    public function expired(HoldExpired $event): void
    {
        unset($this->heldSeats[$event->seatId]);
    }

    #[Apply]
    public function sold(SeatSold $event): void
    {
        unset($this->heldSeats[$event->seatId]);
        $this->soldSeats[$event->seatId] = ['orderId' => $event->orderId, 'paymentReference' => $event->paymentReference];
    }

    /**
     * @return list<string>
     */
    public function seatIdsTakenAt(DateTimeInterface $now): array
    {
        $liveHolds = array_keys(array_filter($this->heldSeats, static fn (array $hold): bool => $hold['heldUntil'] > $now));

        return [...array_keys($this->soldSeats), ...$liveHolds];
    }

    /**
     * @return array{orderId: string, paymentReference: string}|null
     */
    public function saleOf(string $seatId): ?array
    {
        return $this->soldSeats[$seatId] ?? null;
    }

    /**
     * @param list<string> $seatIds
     */
    public function soldEveryOneTo(array $seatIds, string $orderId, string $paymentReference): bool
    {
        foreach ($seatIds as $seatId) {
            if (($this->soldSeats[$seatId] ?? null) !== ['orderId' => $orderId, 'paymentReference' => $paymentReference]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{orderId: string, heldUntil: ?DateTimeImmutable}|null
     */
    public function holdOf(string $seatId): ?array
    {
        return $this->heldSeats[$seatId] ?? null;
    }
}
