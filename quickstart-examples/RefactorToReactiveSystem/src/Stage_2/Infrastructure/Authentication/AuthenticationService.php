<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_2\Infrastructure\Authentication;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * This is just an example.
 * Normally you would fetch it from Session or Access Token.
 */
final class AuthenticationService
{
    public function __construct(private UuidInterface $userId)
    {

    }

    public function getCurrentUserId(): UuidInterface
    {
        return $this->userId;
    }
}