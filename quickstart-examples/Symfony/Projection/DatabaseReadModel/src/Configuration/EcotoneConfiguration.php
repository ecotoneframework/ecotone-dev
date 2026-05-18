<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Configuration;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\SymfonyBundle\Config\SymfonyConnectionReference;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function databaseConnection(): SymfonyConnectionReference
    {
        return SymfonyConnectionReference::defaultConnection('default');
    }
}
