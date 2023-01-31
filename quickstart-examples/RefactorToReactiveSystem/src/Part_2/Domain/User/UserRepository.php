<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Part_2\Domain\User;

use Ramsey\Uuid\UuidInterface;

interface UserRepository
{
    public function getBy(UuidInterface $userId): User;
}