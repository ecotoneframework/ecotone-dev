<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Api\ServiceContext;
use Ecotone\Api\Tempest\TempestConnectionReference;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function databaseConnection(): TempestConnectionReference
    {
        return TempestConnectionReference::defaultConnection();
    }
}
