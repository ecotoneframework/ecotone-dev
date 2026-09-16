<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Fixture\InterfacePayload;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
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
