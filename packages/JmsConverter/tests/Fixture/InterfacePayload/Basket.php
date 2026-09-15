<?php

declare(strict_types=1);

namespace Test\Ecotone\JMSConverter\Fixture\InterfacePayload;

use Ecotone\Api\Aggregate;
use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventHandler;
use Ecotone\Api\Identifier;
use Ecotone\Modelling\WithEvents;

/**
 * licence Apache-2.0
 */
#[Aggregate]
final class Basket
{
    use WithEvents;

    #[Identifier]
    public string $basketId;

    public array $changes = [];

    #[CommandHandler('basket.addProduct')]
    public static function addProduct(array $payload): self
    {
        $basket = new self();
        $basket->basketId = $payload['basketId'];
        $basket->recordThat(new ProductAddedToBasket($payload['basketId'], $payload['productId']));

        return $basket;
    }

    #[Asynchronous('async')]
    #[EventHandler(endpointId: 'basket.trackChange')]
    public function trackChange(BasketContentChanged $event): void
    {
        $this->changes[] = $event::class;
    }
}
