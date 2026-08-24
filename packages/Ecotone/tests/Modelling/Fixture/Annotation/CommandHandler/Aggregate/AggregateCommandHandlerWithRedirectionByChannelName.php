<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Identifier;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class AggregateCommandHandlerWithRedirectionByChannelName
{
    #[Identifier]
    private string $id;

    #[CommandHandler('sameChannel', 'factory')]
    public static function factory(): void
    {
    }

    #[CommandHandler('sameChannel', 'action')]
    public function action(): void
    {
    }
}
