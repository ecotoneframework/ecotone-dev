<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\AsynchronousMessageHandler;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;

#[Asynchronous('async_channel')]
/**
 * licence Apache-2.0
 */
final class AsyncEventHandler
{
    private array $events = [];

    #[EventHandler(endpointId: 'first')]
    public function handleOne(ExampleEvent $event): void
    {
        $this->events[] = $event;
    }

    #[EventHandler(endpointId: 'second')]
    public function handleTwo(ExampleEvent $event): void
    {
        $this->events[] = $event;
    }

    #[QueryHandler('consumer.getEvents')]
    public function getEvents(): array
    {
        return $this->events;
    }
}
