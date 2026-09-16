<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\MultiTenant;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;

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
