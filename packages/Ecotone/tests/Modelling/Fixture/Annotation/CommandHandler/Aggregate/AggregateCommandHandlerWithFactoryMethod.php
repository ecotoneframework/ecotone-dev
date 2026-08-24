<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Identifier;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class AggregateCommandHandlerWithFactoryMethod
{
    #[Identifier]
    private string $id;

    #[CommandHandler(endpointId: 'factory-id')]
    public static function doAction(DoStuffCommand $command): void
    {
    }
}
