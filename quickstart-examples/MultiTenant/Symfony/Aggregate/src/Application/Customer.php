<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Illuminate\Database\Eloquent\Model;

// Important Attribute to tell Ecotone that this is an Aggregate
#[Aggregate]
#[Entity]
#[Table(name: "persons")]
class Customer
{
    // Important Attribute to tell Ecotone that this is an Identifier
    #[Identifier]
    #[Id]
    #[Column(type: "integer", name: "customer_id")]
    private int $customerId;
    #[Column(type: "string")]
    private string $name;

    private function __construct()
    {
    }

    #[CommandHandler]
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
}
