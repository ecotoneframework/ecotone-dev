<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\Dbal;

use Ecotone\Api\ServiceContext;
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
