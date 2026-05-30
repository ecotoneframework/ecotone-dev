<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\Dbal;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Tempest\Config\TempestConnectionReference;

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
