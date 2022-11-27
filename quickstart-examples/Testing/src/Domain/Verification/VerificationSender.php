<?php

declare(strict_types=1);

namespace App\Testing\Domain\Verification;

use App\Testing\Domain\Verification\Command\StartEmailVerification;
use App\Testing\Domain\Verification\Command\StartPhoneNumberVerification;
use Ecotone\Modelling\Attribute\CommandHandler;
use Psr\Log\LoggerInterface;

final class VerificationSender
{
    #[CommandHandler]
    public function startEmailVerification(StartEmailVerification $command, LoggerInterface $logger): void
    {
        $logger->info("Email verification send to " . $command->getEmail()->toString());
    }

    #[CommandHandler]
    public function startPhoneNumberVerification(StartPhoneNumberVerification $command, LoggerInterface $logger): void
    {
        $logger->info("Sms verification send to " . $command->getPhoneNumber()->toString());
    }
}