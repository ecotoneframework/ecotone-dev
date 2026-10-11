<?php

declare(strict_types=1);

namespace Test\Ecotone\Kafka\Fixture\Handler;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;

#[Asynchronous('async')]
/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
