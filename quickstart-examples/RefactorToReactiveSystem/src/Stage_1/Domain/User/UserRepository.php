<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_1\Domain\User;

use Ramsey\Uuid\UuidInterface;

interface UserRepository
{
    public function getBy(UuidInterface $userId): User;
}