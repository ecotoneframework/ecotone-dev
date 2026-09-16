<?php

declare(strict_types=1);

namespace Fixture\MessengerConsumer;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;

#[Asynchronous('messenger_async')]
/**
 * licence Apache-2.0
 */
final class MessengerAsyncEventHandler
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
