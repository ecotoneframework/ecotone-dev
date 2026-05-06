<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant\InvalidPlacement;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Modelling\Attribute\EventHandler;
use stdClass;

/**
 * licence Apache-2.0
 */
final class EventHandlerWithTenantResolver
{
    #[EventHandler]
    #[WithTenantResolver(expression: "headers['source']")]
    public function on(stdClass $event): void
    {
    }
}
