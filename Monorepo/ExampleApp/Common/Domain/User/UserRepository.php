<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\User;

use Ramsey\Uuid\UuidInterface;

interface UserRepository
{
    public function getBy(UuidInterface $userId): User;
}