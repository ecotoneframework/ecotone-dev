<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Command\RegisterCustomer;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\IdentifierMethod;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\Database\Uuid;

#[Aggregate]
#[Table('customers')]
final class Customer
{
    use IsDatabaseModel;

    #[Uuid]
    public PrimaryKey $id;

    public string $name;

    #[CommandHandler]
    public static function register(RegisterCustomer $command): self
    {
        $customer = new self();
        $customer->name = $command->name;
        $customer->save();

        return $customer;
    }

    #[IdentifierMethod('id')]
    public function getId(): string
    {
        return (string) $this->id->value;
    }
}
