<?php

declare(strict_types=1);

namespace App\Sales;

use DateTimeImmutable;
use DateTimeInterface;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\DecisionModel;

#[DecisionModel(tags: ['customer', 'concert'])]
final class CustomerConcertSeats
{
    public const MAXIMUM_SEATS = 4;

    /**
     * @var array<string, array{orderId: string, heldUntil: ?DateTimeImmutable}>
     */
    private array $heldSeats = [];

    /**
     * @var array<string, string>
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
        $this->soldSeats[$event->seatId] = $event->orderId;
    }

    /**
     * @return list<string>
     */
    public function seatIdsTakenAt(DateTimeInterface $now): array
    {
        $liveHolds = array_keys(array_filter($this->heldSeats, static fn (array $hold): bool => $hold['heldUntil'] > $now));

        return [...array_keys($this->soldSeats), ...$liveHolds];
    }

    public function soldToOrderId(string $seatId): ?string
    {
        return $this->soldSeats[$seatId] ?? null;
    }

    /**
     * @return array{orderId: string, heldUntil: ?DateTimeImmutable}|null
     */
    public function holdOf(string $seatId): ?array
    {
        return $this->heldSeats[$seatId] ?? null;
    }
}
