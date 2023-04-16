<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\Order;
use App\Domain\ShippingService;
use Ecotone\Modelling\Attribute\QueryHandler;

final class NetworkFailingShippingService implements ShippingService
{
    private int $counter = 0;
    private bool $isSuccessful = false;

    /**
     * Ta klasa imituje błąd sieciowy, który może wystąpić podczas wysyłki zamówienia.
     * Błąd wystąpi 4 razy, a potem zamówienie zostanie wysłane poprawnie.
     */
    public function ship(Order $order): void
    {
        $this->counter++;

        if ($this->counter <= 4) {
            echo "Network error when calling Shipping Service.\n";
            throw new \RuntimeException("Shipping Service is unavailable.\n");
        }

        $this->isSuccessful = true;
    }

    #[QueryHandler("isShippingSuccessful")]
    public function isSuccessful(): bool
    {
        return $this->isSuccessful;
    }
}