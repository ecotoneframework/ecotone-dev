<?php

namespace Test\Ecotone\EventSourcing\Fixture\BasketWithSynchronousEventDrivenSaga;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionInitialization;
use Ecotone\Messaging\Support\Assert;

#[Projection(self::PROJECTION_NAME)]
#[FromAggregateStream(Saga::class)]
/**
 * licence Apache-2.0
 */
class SagaProjection
{
    public const PROJECTION_NAME = 'saga_projection';
    private bool $isInitialized = false;
    private array $sagaStarted = [];

    #[EventHandler]
    public function when(SagaStarted $event): void
    {
        Assert::isTrue($this->isInitialized, 'Saga Projection is not initialized');
        $this->sagaStarted[$event->getId()] = true;
    }

    #[ProjectionInitialization]
    public function init(): void
    {
        $this->isInitialized = true;
    }

    #[QueryHandler('isSagaStarted')]
    public function isSagaStarted(string $sagaId): bool
    {
        return $this->sagaStarted[$sagaId] ?? false;
    }
}
