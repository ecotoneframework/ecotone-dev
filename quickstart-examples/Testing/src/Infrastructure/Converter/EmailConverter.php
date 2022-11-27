<?php

declare(strict_types=1);

namespace App\Testing\Infrastructure\Converter;

use App\Testing\Domain\User\Email;
use Ecotone\Messaging\Attribute\Converter;

final class EmailConverter
{
    #[Converter]
    public function to(string $email): Email
    {
        return Email::create($email);
    }

    #[Converter]
    public function from(Email $email): string
    {
        return $email->toString();
    }
}