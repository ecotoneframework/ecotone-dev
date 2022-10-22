<?php

namespace App\Microservices\CustomerService\Infrastructure;

use App\Microservices\CustomerService\Domain\Email;
use Ecotone\Messaging\Attribute\Converter;

class EmailConverter
{
    #[Converter]
    public function to(string $email): Email
    {
        return new Email($email);
    }

    #[Converter]
    public function from(Email $email): string
    {
        return $email->address;
    }
}
