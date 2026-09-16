<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Fixture\InterfacePayload;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventSourcingAggregate;
use Ecotone\Api\EventSourcingHandler;
use Ecotone\Api\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

/**
 * licence Apache-2.0
 */
#[EventSourcingAggregate]
final class EventSourcedBasket
{
    use WithAggregateVersioning;

    #[Identifier]
    public string $basketId;

    #[CommandHandler('eventSourcedBasket.addProduct')]
    public static function addProduct(array $payload): array
    {
        return [new ProductAddedToBasket($payload['basketId'], $payload['productId'])];
    }

    #[EventSourcingHandler]
    public function applyProductAdded(ProductAddedToBasket $event): void
    {
        $this->basketId = $event->basketId;
    }
}
