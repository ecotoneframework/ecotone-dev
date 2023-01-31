<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Part_1\Domain\Order;

final class ShippingAddress
{
    public function __construct(private string $street, private string $houseNumber, private string $postCode, private string $country) {}

    public function getStreet(): string
    {
        return $this->street;
    }

    public function getHouseNumber(): string
    {
        return $this->houseNumber;
    }

    public function getPostCode(): string
    {
        return $this->postCode;
    }

    public function getCountry(): string
    {
        return $this->country;
    }
}