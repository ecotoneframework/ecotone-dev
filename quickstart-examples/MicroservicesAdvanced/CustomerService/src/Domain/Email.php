<?php

namespace App\Microservices\CustomerService\Domain;

class Email
{
    public function __construct(public string $address)
    {
        if (!filter_var($this->address, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(sprintf("Email address %s is considered valid.", $this->address));
        }
    }
}
