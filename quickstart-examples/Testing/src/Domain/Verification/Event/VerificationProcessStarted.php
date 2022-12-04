<?php

declare(strict_types=1);

namespace App\Testing\Domain\Verification\Event;

use Ramsey\Uuid\UuidInterface;

final class VerificationProcessStarted
{
    public function __construct(
        private UuidInterface $userId
    ) {}

    public function getUserId(): UuidInterface
    {
        return $this->userId;
    }
}