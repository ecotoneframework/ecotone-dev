<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MultipleAsyncHandlersForOneMessage;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventSourcingAggregate;
use Ecotone\Api\EventSourcingHandler;
use Ecotone\Api\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[Asynchronous('testAggregate')]
#[EventSourcingAggregate]
final class TestAggregate
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $id;

    private int $counter = 0;

    #[CommandHandler(endpointId: 'testAggregate.staticAction')]
    public static function staticAction(ActionCommand $command, array $metadata): array
    {
        return [new ActionCalled($command->id)];
    }

    #[CommandHandler(endpointId: 'testAggregate.action')]
    public function action(ActionCommand $command, array $metadata): array
    {
        return [new ActionCalled($command->id)];
    }

    #[EventSourcingHandler]
    public function applyActionCalled(ActionCalled $event): void
    {
        $this->id = $event->id;
        ++$this->counter;
    }

    public function counter(): int
    {
        return $this->counter;
    }
}
