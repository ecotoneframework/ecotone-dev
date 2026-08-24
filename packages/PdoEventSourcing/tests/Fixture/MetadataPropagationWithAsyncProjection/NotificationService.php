<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\EventHandler;
use Ecotone\Api\QueryHandler;

/**
 * licence Apache-2.0
 */
final class NotificationService
{
    private int $fooCounter = 0;

    #[Asynchronous(OrderProjection::CHANNEL)]
    #[EventHandler(endpointId: 'notification_service.order_created')]
    public function when(OrderCreated $event, array $metadata): void
    {
        if (array_key_exists('foo', $metadata)) {
            $this->fooCounter++;
        }
    }

    #[Asynchronous(OrderProjection::CHANNEL)]
    #[EventHandler(endpointId: 'notification_service.another_order_created')]
    public function another(OrderCreated $event, array $metadata): void
    {
        if (array_key_exists('foo', $metadata)) {
            $this->fooCounter++;
        }
    }

    #[QueryHandler('getNotificationCountWithFoo')]
    public function getNotificationCountWithFoo(): int
    {
        return $this->fooCounter;
    }
}
