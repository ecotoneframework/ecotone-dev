<?php

declare(strict_types=1);

namespace App\Testing\Domain\Verification;

use App\Testing\Domain\User\Event\UserWasRegistered;
use App\Testing\Domain\Verification\Command\StartEmailVerification;
use App\Testing\Domain\Verification\Command\StartPhoneNumberVerification;
use Ecotone\Messaging\Attribute\Endpoint\Delayed;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\Saga;
use Ecotone\Modelling\CommandBus;
use Ramsey\Uuid\UuidInterface;

#[Saga]
final class VerificationProcess
{
    private function __construct(
        private UuidInterface           $userId,
        private EmailVerification       $emailVerification,
        private PhoneNumberVerification $phoneNumberVerification
    )
    {
    }

    #[EventHandler]
    public static function start(UserWasRegistered $event, CommandBus $commandBus): self
    {
        $self = new self(
            $event->getUserId(),
            new EmailVerification($event->getEmail(), VerificationToken::generate(), false),
            new PhoneNumberVerification($event->getPhoneNumber(), VerificationToken::generate(), false)
        );

        $commandBus->send(new StartEmailVerification($self->emailVerification->getEmail(), $self->emailVerification->getVerificationToken()));
        $commandBus->send(new StartPhoneNumberVerification($self->phoneNumberVerification->getPhoneNumber(), $self->phoneNumberVerification->getVerificationToken()));

        return $self;
    }

    #[Delayed(1000 * 60 * 60 * 24)] // execute 24 hours after registration
    #[EventHandler]
    public function timeout(UserWasRegistered $userWasRegistered, CommandBus $commandBus): void
    {
        if ($this->emailVerification->isVerified() && $this->phoneNumberVerification->isVerified()) {
            return;
        }

        $commandBus->sendWithRouting("user.block", metadata: ["aggregate.id" => $this->userId]);
    }
}