<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterProduct;
use App\MultiTenant\Application\Command\UnregisterProduct;
use App\MultiTenant\Application\Event\ProductWasRegistered;
use App\MultiTenant\Application\Event\ProductWasUnregistered;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;
use Ramsey\Uuid\UuidInterface;

#[EventSourcingAggregate]
final class Product
{
    #[Identifier]
    private UuidInterface $productId;

    use WithAggregateVersioning;

    #[CommandHandler]
    public static function register(RegisterProduct $command): array
    {
        return [
            new ProductWasRegistered($command->productId, $command->name)
        ];
    }

    #[CommandHandler]
    public function unregister(UnregisterProduct $command): array
    {
        return [
            new ProductWasUnregistered($command->productId)
        ];
    }

    #[EventSourcingHandler]
    public function applyProductWasRegistered(ProductWasRegistered $event): void
    {
        $this->productId = $event->productId;
    }
}