<?php

namespace Test\Ecotone\Messaging\Fixture\InterceptorsOrdering;

use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\WithEvents;

#[Aggregate]
class InterceptorOrderingAggregate
{
    use WithEvents;

    public function __construct(
        #[Identifier] private string $id,
    )
    {
        $this->recordThat(new CreatedEvent());
    }


    #[CommandHandler(routingKey: "endpoint")]
    public static function factory(#[Headers] array $metadata): self
    {
        $stack = $metadata["stack"];
        $stack->add("factory", $metadata);
        return new self($metadata["aggregate.id"] ?? "id");
    }

    #[CommandHandler(routingKey: "endpoint")]
    public function action(#[Headers] array $metadata): void
    {
        $stack = $metadata["stack"];
        $stack->add("action", $metadata);
    }
}