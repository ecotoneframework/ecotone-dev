<?php

namespace Test\Ecotone\Modelling\Fixture\Annotation\CommandHandler\Aggregate;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Identifier;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class AggregateCommandHandlerWithDoubledFactoryMethod
{
    #[Identifier]
    private string $id;

    #[CommandHandler('sameChannel')]
    public static function factory(): void
    {
    }

    #[CommandHandler('sameChannel')]
    public static function factory2(): void
    {
    }
}
