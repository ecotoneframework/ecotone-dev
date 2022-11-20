<?php

declare(strict_types=1);

namespace App\Testing\Domain\User\Event;

use App\Testing\Domain\User\Email;
use App\Testing\Domain\User\PhoneNumber;
use Ramsey\Uuid\UuidInterface;

final class UserWasRegistered
{
    public function __construct(
        private UuidInterface $userId,
        private Email $email,
        private PhoneNumber $phoneNumber
    ) {}

    public function getUserId(): UuidInterface
    {
        return $this->userId;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }

    public function getPhoneNumber(): PhoneNumber
    {
        return $this->phoneNumber;
    }
}