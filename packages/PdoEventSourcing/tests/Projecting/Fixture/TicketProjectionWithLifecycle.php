<?php

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
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionInitialization;
use RuntimeException;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketCreated;

#[Projection(self::NAME)]
class TicketProjectionWithLifecycle
{
    public const NAME = 'projection_with_lifecycle';
    private bool $initialized = false;
    private array $projectedEvents = [];
    #[EventHandler]
    public function on(TicketCreated $event): void
    {
        if (! $this->initialized) {
            throw new RuntimeException('Projection not initialized');
        }
        $this->projectedEvents[] = $event;
    }

    #[ProjectionInitialization]
    public function init(): void
    {
        if ($this->initialized) {
            throw new RuntimeException('Projection already initialized');
        }
        $this->initialized = true;
    }

    public function getProjectedEvents(): array
    {
        return $this->projectedEvents;
    }
}
