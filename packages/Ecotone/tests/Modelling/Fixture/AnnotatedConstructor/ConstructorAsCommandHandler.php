<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\AnnotatedConstructor;

use Ecotone\Api\Aggregate;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Identifier;

#[Aggregate]
/**
 * licence Apache-2.0
 */
final class ConstructorAsCommandHandler
{
    #[Identifier]
    private string $id;

    #[CommandHandler(routingKey: 'test')]
    public function __construct()
    {
    }
}
