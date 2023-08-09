<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;

#[Aggregate]
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
