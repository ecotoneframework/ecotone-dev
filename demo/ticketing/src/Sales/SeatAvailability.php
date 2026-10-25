<?php

declare(strict_types=1);

namespace App\Sales;

use DateTimeImmutable;
use DateTimeInterface;
use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\DecisionModel;

#[DecisionModel(tags: ['seat'])]
final class SeatAvailability
{
    private ?string $holdingOrderId = null;

    private ?DateTimeImmutable $heldUntil = null;

    private ?string $soldToOrderId = null;

    #[Apply]
    public function held(SeatHeld $event): void
    {
        $this->holdingOrderId = $event->orderId;
        $this->heldUntil = $event->expiresAt;
    }

    #[Apply]
    public function released(HoldReleased $event): void
    {
        $this->free();
    }

    #[Apply]
    public function expired(HoldExpired $event): void
    {
        $this->free();
    }

    #[Apply]
    public function sold(SeatSold $event): void
    {
        $this->free();
        $this->soldToOrderId = $event->orderId;
    }

    public function soldToOrderId(): ?string
    {
        return $this->soldToOrderId;
    }

    public function holdingOrderId(): ?string
    {
        return $this->holdingOrderId;
    }

    public function liveHoldingOrderIdAt(DateTimeInterface $now): ?string
    {
        return $this->heldUntil !== null && $this->heldUntil > $now ? $this->holdingOrderId : null;
    }

    public function heldUntil(): ?DateTimeImmutable
    {
        return $this->heldUntil;
    }

    private function free(): void
    {
        $this->holdingOrderId = null;
        $this->heldUntil = null;
    }
}
