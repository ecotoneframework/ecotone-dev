<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Identifier;
use Ecotone\Api\QueryHandler;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class AggregateWithNoParametersAndInputChannelAndNoIgnoreMessage
{
    #[Identifier]
    private string $id;

    #[CommandHandler('command', 'endpoint-command')]
    public function doCommand(): void
    {
    }

    #[QueryHandler('query', 'endpoint-query')]
    public function doQuery()
    {
    }
}
