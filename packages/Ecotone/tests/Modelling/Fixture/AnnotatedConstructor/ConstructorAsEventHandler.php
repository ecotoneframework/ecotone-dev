<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\AnnotatedConstructor;

use Ecotone\Api\Aggregate;
use Ecotone\Api\EventHandler;
use Ecotone\Api\Identifier;
use stdClass;

#[Aggregate]
/**
 * licence Apache-2.0
 */
final class ConstructorAsEventHandler
{
    #[Identifier]
    private string $id;

    #[EventHandler(endpointId: 'commandHandler')]
    public function __construct(stdClass $event)
    {
    }
}
