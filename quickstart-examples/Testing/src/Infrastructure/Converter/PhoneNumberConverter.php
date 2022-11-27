<?php

declare(strict_types=1);

namespace App\Testing\Infrastructure\Converter;

use App\Testing\Domain\User\PhoneNumber;
use Ecotone\Messaging\Attribute\Converter;

final class PhoneNumberConverter
{
    #[Converter]
    public function to(string $phoneNumber): PhoneNumber
    {
        return PhoneNumber::create($phoneNumber);
    }

    #[Converter]
    public function from(PhoneNumber $phoneNumber): string
    {
        return $phoneNumber->toString();
    }
}