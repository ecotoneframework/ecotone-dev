<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Command\ChangePrice;
use App\Domain\Command\RegisterProduct;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\IdentifierMethod;
use Ecotone\Modelling\Attribute\QueryHandler;
use Tempest\Database\IsDatabaseModel;
use Tempest\Database\PrimaryKey;

#[Aggregate]
final class Product
{
    use IsDatabaseModel;

    public PrimaryKey $id;

    public string $name;

    public int $price;

    #[CommandHandler]
    public static function register(RegisterProduct $command): self
    {
        $product = new self();
        $product->name = $command->name;
        $product->price = $command->price;
        $product->save();

        return $product;
    }

    #[CommandHandler(routingKey: 'product.changePrice')]
    public function changePrice(ChangePrice $command): void
    {
        $this->price = $command->price;
    }

    #[QueryHandler('product.getPrice')]
    public function getPrice(): int
    {
        return $this->price;
    }

    #[IdentifierMethod('id')]
    public function getId(): int
    {
        return $this->id->value;
    }
}
