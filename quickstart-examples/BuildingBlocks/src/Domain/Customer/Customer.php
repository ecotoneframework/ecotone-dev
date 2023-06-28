<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use App\Domain\Customer\Command\ChangeEmail;
use App\Domain\Customer\Command\RegisterCustomer;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ramsey\Uuid\UuidInterface;

/**
 * @link https://docs.ecotone.tech/modelling/command-handling/state-stored-aggregate
 */
#[Aggregate]
final class Customer
{
    private function __construct(
        #[AggregateIdentifier] private UuidInterface $customerId,
        private FullName $fullName,
        private Email $email,
    ) {}

    #[CommandHandler]
    public static function register(RegisterCustomer $command): self
    {
        return new self(
            $command->customerId,
            $command->fullName,
            $command->email
        );
    }

    #[CommandHandler]
    public function changeEmail(ChangeEmail $command): void
    {
        $this->email = $command->email;
    }

    public function getEmail(): Email
    {
        return $this->email;
    }
}