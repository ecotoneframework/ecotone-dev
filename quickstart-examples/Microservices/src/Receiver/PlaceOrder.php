<?php declare(strict_types=1);

namespace App\Microservices\Receiver;

class PlaceOrder
{
    private int $personId;
    /** @var string[] */
    private array $products;

    public function getPersonId(): int
    {
        return $this->personId;
    }

    public function getProducts(): array
    {
        return $this->products;
    }
}