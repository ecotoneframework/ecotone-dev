<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Identifier;
use stdClass;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class AggregateCommandHandlerWithReferencesExample
{
    #[Identifier]
    private string $id;

    #[CommandHandler('input', 'command-id-with-references')]
    public function doAction(DoStuffCommand $command, stdClass $injectedService): void
    {
    }
}
