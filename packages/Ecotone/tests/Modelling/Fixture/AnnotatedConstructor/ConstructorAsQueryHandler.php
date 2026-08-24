<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\AnnotatedConstructor;

use Ecotone\Api\Aggregate;
use Ecotone\Api\Identifier;
use Ecotone\Api\QueryHandler;

#[Aggregate]
/**
 * licence Apache-2.0
 */
final class ConstructorAsQueryHandler
{
    #[Identifier]
    private string $id;

    #[QueryHandler(routingKey: 'test')]
    public function __construct()
    {
    }
}
