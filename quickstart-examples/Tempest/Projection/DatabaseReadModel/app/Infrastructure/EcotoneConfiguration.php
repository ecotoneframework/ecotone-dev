<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Tempest\Config\TempestConnectionReference;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function databaseConnection(): TempestConnectionReference
    {
        return TempestConnectionReference::defaultConnection();
    }
}
