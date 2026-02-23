<?php

declare(strict_types=1);

namespace Test\Ecotone\DataProtection\Fixture\PersistingSensitiveEvents;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
class SomeAggregate
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $id;

    #[CommandHandler]
    public static function create(SomeCommand $command): array
    {
        return [
            new AggregateEvent(
                id: $command->id,
                sensitiveValue: $command->value,
                sensitiveEnum: $command->enum,
                sensitiveObject: $command->object
            ),
        ];
    }

    #[CommandHandler]
    public function handle(SomeCommand $command): array
    {
        return [
            new AggregateEvent(
                id: $command->id,
                sensitiveValue: $command->value,
                sensitiveEnum: $command->enum,
                sensitiveObject: $command->object
            ),
        ];
    }

    #[EventSourcingHandler]
    public function applyAggregateEvent(AggregateEvent $event): void
    {
        $this->id = $event->id;
    }
}
