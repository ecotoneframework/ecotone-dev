<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_2\Domain\User;

use Ramsey\Uuid\UuidInterface;

final class User
{
    public function __construct(private UuidInterface $userId, private string $fullName)
    {

    }

    public function getUserId(): UuidInterface
    {
        return $this->userId;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }
}