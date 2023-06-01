<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

final class NotificationService
{
    private int $fooCounter = 0;

    /**
     * @TODO After enabling this we are having Projection Event Handler and Messaging Event Handler for same event
     * This fails and it seems it reproduces: https://github.com/ecotoneframework/ecotone-dev/issues/104
     */
//    #[Asynchronous(OrderProjection::CHANNEL)]
//    #[EventHandler(endpointId: 'notification_service.order_created')]
    public function when(OrderCreated $event, array $metadata): void
    {
        if (array_key_exists('foo', $metadata)) {
            $this->fooCounter++;
        }
    }

//    #[Asynchronous(OrderProjection::CHANNEL)]
//    #[EventHandler(endpointId: 'notification_service.another_order_created')]
    public function another(OrderCreated $event, array $metadata): void
    {
        if (array_key_exists('foo', $metadata)) {
            $this->fooCounter++;
        }
    }

    #[QueryHandler("getNotificationCountWithFoo")]
    public function getNotificationCountWithFoo(): int
    {
        return $this->fooCounter;
    }
}
