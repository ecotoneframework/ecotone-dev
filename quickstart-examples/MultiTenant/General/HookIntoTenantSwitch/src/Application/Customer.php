<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Illuminate\Database\Eloquent\Model;

#[Aggregate]
class Customer
{
    #[Identifier]
    private int $customerId;
    private string $name;

    private function __construct(int $customerId, string $name)
    {
        $this->customerId = $customerId;
        $this->name = $name;
    }

    #[CommandHandler]
    public static function register(RegisterCustomer $command): static
    {
        return new self($command->customerId, $command->name);
    }

    public function getName(): string
    {
        return $this->name;
    }
}
