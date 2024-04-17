<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Illuminate\Database\Eloquent\Model;

#[Entity]
#[Table(name: "persons")]
class Customer
{
    #[Id]
    #[Column(type: "integer", name: "customer_id")]
    private int $customerId;
    #[Column(type: "string")]
    private string $name;

    private function __construct()
    {
    }

    public static function register(RegisterCustomer $command): static
    {
        $self = new self();
        $self->customerId = $command->customerId;
        $self->name = $command->name;

        return $self;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCustomerId(): int
    {
        return $this->customerId;
    }
}
