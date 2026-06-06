<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\SharedConnection;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Tempest\Config\TempestConnectionReference;

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
