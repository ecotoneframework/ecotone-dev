<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\MultiTenant;

use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class CustomerService
{
    #[CommandHandler]
    public function handle(RegisterCustomer $command, CustomerInterface $customerInterface): void
    {
        $customerInterface->register($command->customerId, $command->name);
    }

    #[CommandHandler('customer.register_with_business_interface')]
    public function handleWithDbalInterface(RegisterCustomer $command, CustomerInterface $customerInterface): void
    {
        $customerInterface->register($command->customerId, $command->name);
    }

    #[QueryHandler('customer.getAllRegistered')]
    public function getAllRegisteredPersonIds(#[Reference] CustomerRepository $customerRepository): array
    {
        return $customerRepository->getAllRegisteredPersonIds();
    }
}
