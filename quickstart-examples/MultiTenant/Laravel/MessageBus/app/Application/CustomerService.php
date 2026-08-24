<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Illuminate\Support\Facades\DB;

final readonly class CustomerService
{
    #[CommandHandler]
    public function handle(RegisterCustomer $command)
    {
        Customer::register($command)->save();
    }

    #[QueryHandler('customer.getAllRegistered')]
    public function getAllRegisteredPersonIds(): array
    {
        return DB::connection()->getPdo()->query(<<<SQL
            SELECT customer_id FROM persons;    
SQL)->fetchAll(\PDO::FETCH_COLUMN);
    }
}