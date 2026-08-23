<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Infrastructure;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Tempest\Api\ExtensionObject\TempestConnectionReference;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function databaseConnection(): TempestConnectionReference
    {
        return TempestConnectionReference::defaultConnection();
    }
}
