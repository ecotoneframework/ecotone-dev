<?php

declare(strict_types=1);

namespace App\Domain\Customer\Command;

use App\Domain\Customer\Email;
use Ramsey\Uuid\UuidInterface;

final readonly class ChangeEmail
{
    public function __construct(
        public UuidInterface $customerId,
        public Email $email
    ) {}
}