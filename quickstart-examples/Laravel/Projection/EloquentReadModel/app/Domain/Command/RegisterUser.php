<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Domain\Command;

final readonly class RegisterUser
{
    public function __construct(
        public string $userId,
        public string $name,
        public string $email,
    ) {
    }
}
