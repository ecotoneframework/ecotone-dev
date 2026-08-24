<?php

namespace Test\Ecotone\Messaging\Fixture\InterceptorsOrdering;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Headers;
use Ecotone\Api\Identifier;
use Ecotone\Api\Reference;
use Ecotone\Modelling\WithEvents;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class InterceptorOrderingAggregate
{
    use WithEvents;

    public function __construct(
        #[Identifier] private string $id,
    ) {
        $this->recordThat(new CreatedEvent());
    }


    #[CommandHandler(routingKey: 'endpoint')]
    public static function factory(#[Reference] InterceptorOrderingStack $stack, #[Headers] array $metadata): self
    {
        $stack->add('factory');
        return new self($metadata['aggregate.id'] ?? 'id');
    }

    #[CommandHandler(routingKey: 'endpointFactoryWithOutput', outputChannelName: 'internal-channel')]
    public static function factoryWithOutput(#[Reference] InterceptorOrderingStack $stack, #[Headers] array $metadata): self
    {
        $stack->add('factory');
        return new self($metadata['aggregate.id'] ?? 'id');
    }

    #[CommandHandler(routingKey: 'endpoint')]
    public function action(#[Reference] InterceptorOrderingStack $stack): void
    {
        $stack->add('action');
    }

    #[CommandHandler(routingKey: 'actionVoid')]
    public function actionVoid(#[Reference] InterceptorOrderingStack $stack): void
    {
        $stack->add('action');
    }

    #[CommandHandler(routingKey: 'endpointWithOutput', outputChannelName: 'internal-channel')]
    public function actionWithOutputChannel(#[Reference] InterceptorOrderingStack $stack): mixed
    {
        $stack->add('action');

        return 'something';
    }
}
