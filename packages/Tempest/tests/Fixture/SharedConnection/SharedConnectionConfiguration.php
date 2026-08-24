<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\SharedConnection;

use Ecotone\Api\ServiceContext;
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
