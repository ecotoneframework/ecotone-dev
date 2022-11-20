<?php

declare(strict_types=1);

namespace App\Testing\Domain\User\Command;

use App\Testing\Domain\User\Email;
use App\Testing\Domain\User\PhoneNumber;
use Ramsey\Uuid\UuidInterface;

final class RegisterUser
{
    public function __construct(
        private UuidInterface $userId,
        private string $name,
        private Email $email,
        private PhoneNumber $phoneNumber
    ) {}

    public function getUserId(): UuidInterface
    {
        return $this->userId;
    }

    public function getName(): string
    {
        return $this->name;
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