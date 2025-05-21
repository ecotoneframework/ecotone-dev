<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting\Fixture;

use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\Attribute\Projection;

#[Projection(self::NAME)]
class ProjectionWithLifecycle
{
    public const NAME = 'projection_with_lifecycle';
    private bool $initialized = false;
    private array $projectedEvents = [];
    #[EventHandler]
    public function on(TicketCreated $event): void
    {
        if (!$this->initialized) {
            throw new \RuntimeException('Projection not initialized');
        }
        $this->projectedEvents[] = $event;
    }

    #[ProjectionInitialization]
    public function init(): void
    {
        if ($this->initialized) {
            throw new \RuntimeException('Projection already initialized');
        }
        $this->initialized = true;
    }

    public function getProjectedEvents(): array
    {
        return $this->projectedEvents;
    }
}