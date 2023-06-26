<?php

declare(strict_types=1);

namespace App\Domain\Customer\Command;

use App\Domain\Customer\Email;
use App\Domain\Customer\FullName;
use Ramsey\Uuid\UuidInterface;

final readonly class RegisterCustomer
{
    public function __construct(
        public UuidInterface $customerId,
        public FullName $fullName,
        public Email $email,
    ) {}
}