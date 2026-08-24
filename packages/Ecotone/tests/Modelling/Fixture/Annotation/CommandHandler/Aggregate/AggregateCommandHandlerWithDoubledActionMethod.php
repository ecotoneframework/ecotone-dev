<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Identifier;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class AggregateCommandHandlerWithDoubledActionMethod
{
    #[Identifier]
    private string $id;

    #[CommandHandler('sameChannel')]
    public function action1(): void
    {
    }

    #[CommandHandler('sameChannel')]
    public function action2(): void
    {
    }
}
