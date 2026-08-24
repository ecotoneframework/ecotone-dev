<?php

namespace Test\Ecotone\Modelling\Fixture\MetadataPropagatingForMultipleEndpoints;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventBus;
use Ecotone\Api\EventHandler;
use Ecotone\Api\QueryHandler;
use InvalidArgumentException;

/**
 * licence Apache-2.0
 */
class OrderService
{
    private array $notificationHeaders = [];

    private array $notifyWithCustomHeaders = [];

    #[CommandHandler('placeOrder')]
    public function doSomething($command, array $headers, EventBus $eventBus): void
    {
        $eventBus->publish(new OrderWasPlaced());
    }

    #[CommandHandler('failAction')]
    public function failAction(): void
    {
        throw new InvalidArgumentException('failed action');
    }

    #[EventHandler]
    public function notifyBySms(OrderWasPlaced $event, array $headers, EventBus $eventBus): void
    {
        $this->notificationHeaders[] = $headers;
    }

    #[Asynchronous('notifications')]
    #[EventHandler(endpointId: 'notificationEndpoint')]
    public function notifyByEmail(OrderWasPlaced $event, array $headers, EventBus $eventBus): void
    {
        $this->notificationHeaders[] = $headers;
    }

    #[CommandHandler('setCustomNotificationHeaders')]
    public function notifyWithCustomerHeaders(array $payload, array $headers): void
    {
        $this->notifyWithCustomHeaders = $headers;
    }

    #[QueryHandler('getNotificationHeaders')]
    public function getNotificationHeaders(): array
    {
        return array_shift($this->notificationHeaders);
    }
}
