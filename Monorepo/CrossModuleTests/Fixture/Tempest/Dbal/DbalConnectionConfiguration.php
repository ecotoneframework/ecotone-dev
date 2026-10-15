<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\Tempest\Dbal;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Tempest\TempestConnectionReference;

/**
 * licence Apache-2.0
 */
final class DbalConnectionConfiguration
{
    #[ServiceContext]
    public function dbalConnection(): TempestConnectionReference
    {
        return TempestConnectionReference::defaultConnection();
    }
}
