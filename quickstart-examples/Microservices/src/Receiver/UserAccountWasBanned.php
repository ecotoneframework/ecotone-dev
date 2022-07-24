<?php declare(strict_types=1);

namespace App\Microservices\Receiver;

class UserAccountWasBanned
{
    private int $personId;

    public function getPersonId(): int
    {
        return $this->personId;
    }
}