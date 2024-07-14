<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\OrderAggregate;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
class LoggingService
{
    private $logging = [];

    #[Asynchronous('orders')]
    #[EventHandler(endpointId: 'loggingService')]
    public function log(OrderWasNotified $event): void
    {
        $this->logging[] = $event->getOrderId();
    }

    #[QueryHandler('getLogs')]
    public function getLoggedEvents(): array
    {
        return $this->logging;
    }
}
