<?php

declare(strict_types=1);

namespace Monorepo\CrossModuleTests\Fixture\Tempest\SharedConnection;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Tempest\TempestConnectionReference;

/**
 * licence Apache-2.0
 */
final class SharedConnectionConfiguration
{
    #[ServiceContext]
    public function connection(): TempestConnectionReference
    {
        return TempestConnectionReference::defaultConnection();
    }
}
