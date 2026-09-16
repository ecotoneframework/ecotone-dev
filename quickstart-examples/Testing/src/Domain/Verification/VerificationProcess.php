<?php

declare(strict_types=1);

namespace App\Testing\Domain\Verification;

use App\Testing\Domain\User\Event\UserWasRegistered;
use App\Testing\Domain\Verification\Command\StartEmailVerification;
use App\Testing\Domain\Verification\Command\StartPhoneNumberVerification;
use App\Testing\Domain\Verification\Command\VerifyEmail;
use App\Testing\Domain\Verification\Command\VerifyPhoneNumber;
use App\Testing\Domain\Verification\Event\VerificationProcessStarted;
use App\Testing\Infrastructure\MessagingConfiguration;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Delayed;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Saga;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Modelling\WithEvents;
use Ramsey\Uuid\UuidInterface;

#[Saga]
final class VerificationProcess
{
    use WithEvents;

    private function __construct(
        #[Identifier]
        private UuidInterface           $userId,
        private EmailVerification       $emailVerification,
        private PhoneNumberVerification $phoneNumberVerification
    )
    {
        $this->recordThat(new VerificationProcessStarted($this->userId));
    }

    #[EventHandler]
    public static function start(UserWasRegistered $event, TokenGenerator $tokenGenerator, CommandBus $commandBus): self
    {
        $self = new self(
            $event->getUserId(),
            new EmailVerification($event->getEmail(), $tokenGenerator->generate(), false),
            new PhoneNumberVerification($event->getPhoneNumber(), $tokenGenerator->generate(), false)
        );

        $commandBus->send(new StartEmailVerification($self->emailVerification->getEmail(), $self->emailVerification->getVerificationToken()));
        $commandBus->send(new StartPhoneNumberVerification($self->phoneNumberVerification->getPhoneNumber(), $self->phoneNumberVerification->getVerificationToken()));

        return $self;
    }

    #[CommandHandler]
    public function verifyEmail(VerifyEmail $command, CommandBus $commandBus): void
    {
        if (!$this->emailVerification->isTokenEqual($command->getVerificationToken())) {
            throw new \InvalidArgumentException("Token incorrect");
        }

        $this->emailVerification = $this->emailVerification->finishVerificationWithSuccess();

        if ($this->phoneNumberVerification->isVerified()) {
            $commandBus->sendWithRouting("user.verify", metadata: ["aggregate.id" => $this->userId]);
        }
    }

    #[CommandHandler]
    public function verifySms(VerifyPhoneNumber $command, CommandBus $commandBus): void
    {
        if (!$this->phoneNumberVerification->isTokenEqual($command->getVerificationToken())) {
            throw new \InvalidArgumentException("Token incorrect");
        }

        $this->phoneNumberVerification = $this->phoneNumberVerification->finishVerificationWithSuccess();

        if ($this->emailVerification->isVerified()) {
            $commandBus->sendWithRouting("user.verify", metadata: ["aggregate.id" => $this->userId]);
        }
    }

    #[Delayed(1000 * 60 * 60 * 24)] // execute 24 hours after registration
    #[Asynchronous(MessagingConfiguration::ASYNCHRONOUS_MESSAGES)]
    #[EventHandler(endpointId: "verificationProcess.timeout")]
    public function timeout(VerificationProcessStarted $event, CommandBus $commandBus): void
    {
        if ($this->emailVerification->isVerified() && $this->phoneNumberVerification->isVerified()) {
            return;
        }

        $commandBus->sendWithRouting("user.block", metadata: ["aggregate.id" => $this->userId]);
    }
}