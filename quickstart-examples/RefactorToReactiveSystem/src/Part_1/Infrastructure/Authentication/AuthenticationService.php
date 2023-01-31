<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Part_1\Infrastructure\Authentication;

use Ramsey\Uuid\UuidInterface;

interface AuthenticationService
{
    public function getCurrentUserId(): UuidInterface;
}