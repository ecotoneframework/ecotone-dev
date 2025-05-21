<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting\Fixture;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\Attribute\Projection;

#[Projection(self::NAME)]
#[Asynchronous(self::ASYNC_CHANNEL)]
class AsynchronousProjection
{
    public const NAME = 'projection_with_async';
    public const ASYNC_CHANNEL = 'async_projection';

    private array $projectedEvents = [];
    #[EventHandler]
    public function on(TicketCreated $event): void
    {
        $this->projectedEvents[] = $event;
    }

    public function getProjectedEvents(): array
    {
        return $this->projectedEvents;
    }
}