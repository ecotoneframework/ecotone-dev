<?php

namespace Test\Ecotone\Modelling\Fixture\NoIdentifierAggregate;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;

#[Aggregate]
/**
 * licence Apache-2.0
 */
class Product
{
    #[CommandHandler('create')]
    public static function create(array $payload): self
    {
        return new self();
    }
}
