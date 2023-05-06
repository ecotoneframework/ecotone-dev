<?php

declare(strict_types=1);

namespace Fixture\MessengerConsumer;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

#[Asynchronous('messenger_async')]
final class MessengerAsyncEventHandler
{
    private array $events = [];

    #[EventHandler(endpointId: "first")]
    public function handleOne(ExampleEvent $event): void
    {
        $this->events[] = $event;
    }

    #[EventHandler(endpointId: "second")]
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