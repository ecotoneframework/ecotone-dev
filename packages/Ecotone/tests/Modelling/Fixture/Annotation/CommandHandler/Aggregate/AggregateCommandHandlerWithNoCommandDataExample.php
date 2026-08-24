<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Identifier;
use Ecotone\Api\IgnorePayload;
use stdClass;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class AggregateCommandHandlerWithNoCommandDataExample
{
    #[Identifier]
    private string $id;

    #[CommandHandler('doActionChannel', 'command-id')]
    #[IgnorePayload]
    public function doAction(stdClass $class): void
    {
    }
}
