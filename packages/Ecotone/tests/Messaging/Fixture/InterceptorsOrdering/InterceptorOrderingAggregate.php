<?php

namespace Test\Ecotone\Messaging\Fixture\InterceptorsOrdering;

use Ecotone\Messaging\Attribute\Interceptor\After;
use Ecotone\Messaging\Attribute\Interceptor\Around;
use Ecotone\Messaging\Attribute\Interceptor\Before;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\Saga;

#[Aggregate]
class InterceptorOrderingAggregate
{
    public function __construct(
        #[Identifier] private string $id,
    )
    {
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