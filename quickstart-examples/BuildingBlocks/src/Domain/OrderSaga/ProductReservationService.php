<?php

declare(strict_types=1);

namespace App\Domain\OrderSaga;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ramsey\Uuid\UuidInterface;

class ProductReservationService
{
    public function __construct(
        private bool $result = true
    ) {}

    #[CommandHandler("allow_product_reservation")]
    public function changeToReservationToSuccess(): void
    {
        $this->result = true;
    }

    /**
     * @param UuidInterface[] $productIds
     * @return bool if reservation was successful
     */
    public function reserveProducts(UuidInterface $orderId, array $productIds): bool
    {
        /** This is just for testing purposes, normally we would call some external service here */
        return $this->result;
    }
}