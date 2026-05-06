<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant\InvalidPlacement;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;

/**
 * licence Apache-2.0
 */
final class AsynchronousHandlerWithTenantResolver
{
    #[Asynchronous('async_invalid_channel')]
    #[CommandHandler('asyncInvalidPlacement', endpointId: 'asyncInvalidPlacementEndpoint')]
    #[WithTenantResolver(expression: "headers['source']")]
    public function handle(string $payload): void
    {
    }
}
