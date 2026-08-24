<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Identifier;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class AggregateCommandHandlerExample
{
    #[Identifier]
    private string $id;

    #[CommandHandler(endpointId: 'command-id')]
    public function doAction(DoStuffCommand $command): void
    {
    }
}
