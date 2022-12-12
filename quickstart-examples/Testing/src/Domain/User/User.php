<?php

declare(strict_types=1);

namespace App\Testing\Domain\User;

use App\Testing\Domain\User\Command\RegisterUser;
use App\Testing\Domain\User\Event\UserWasRegistered;
use Assert\Assert;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
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
        private PhoneNumber $phoneNumber,
        private bool $isBlocked = false,
        private bool $isVerified = false
    ) {
        $this->recordThat(new UserWasRegistered($this->userId, $this->email, $this->phoneNumber));
    }

    #[CommandHandler]
    #[CommandHandler("user.register")]
    public static function register(RegisterUser $command): self
    {
        return new self(
            $command->getUserId(),
            $command->getName(),
            $command->getEmail(),
            $command->getPhoneNumber()
        );
    }

    #[CommandHandler("user.block")]
    public function block(): void
    {
        if ($this->isBlocked) {
            return;
        }

        $this->isBlocked = true;
    }

    #[CommandHandler("user.verify")]
    public function verify(): void
    {
        if ($this->isVerified) {
            return;
        }

        Assert::that($this->isBlocked, "Can't verify blocked user")->false();

        $this->isVerified = true;
    }

    public function isBlocked(): bool
    {
        return $this->isBlocked;
    }

    #[QueryHandler("user.isVerified")]
    public function isVerified(): bool
    {

    }
}