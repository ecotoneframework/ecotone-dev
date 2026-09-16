<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\TenantAggregate;

use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\IdentifierMethod;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\Database\Uuid;

/**
 * licence Apache-2.0
 */
#[Aggregate]
#[Table('tenant_products')]
final class TenantProduct
{
    use IsDatabaseModel;

    #[Uuid]
    public PrimaryKey $id;

    public string $name;

    #[CommandHandler]
    public static function register(RegisterTenantProduct $command): self
    {
        $product = new self();
        $product->name = $command->name;
        $product->save();

        return $product;
    }

    #[IdentifierMethod('id')]
    public function getId(): string
    {
        return (string) $this->id->value;
    }
}
