<?php

declare(strict_types=1);

namespace App\Testing\Domain\User;

use App\Testing\Domain\User\Command\RegisterUser;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\WithAggregateEvents;
use Ramsey\Uuid\UuidInterface;

#[Aggregate]
final class User
{
    use WithAggregateEvents;

    public function __construct(
        #[AggregateIdentifier] private UuidInterface $userId,
        private string $name,
        private Email $email,
        private PhoneNumber $phoneNumber
    ) {}

    #[CommandHandler]
    public static function register(RegisterUser $command): self
    {
        return new self(
            $command->getUserId(),
            $command->getName(),
            $command->getEmail(),
            $command->getPhoneNumber()
        );
    }
}