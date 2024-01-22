<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

final readonly class RegisterPerson
{
    public function __construct(public int $personId, public string $name)
    {
    }
}
