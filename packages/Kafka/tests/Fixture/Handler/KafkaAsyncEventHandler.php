<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\Handler;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\EventHandler;
use Ecotone\Api\QueryHandler;

#[Asynchronous('async')]
/**
 * licence Enterprise
 */
final class KafkaAsyncEventHandler
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
