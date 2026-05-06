<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\MultiTenant\InvalidPlacement;

use Ecotone\Dbal\Attribute\WithTenantResolver;
use Ecotone\Modelling\Attribute\CommandHandler;

/**
 * licence Apache-2.0
 */
final class CommandHandlerWithTenantResolver
{
    #[CommandHandler('invalidPlacement')]
    #[WithTenantResolver(expression: "headers['source']")]
    public function handle(string $payload): void
    {
    }
}
