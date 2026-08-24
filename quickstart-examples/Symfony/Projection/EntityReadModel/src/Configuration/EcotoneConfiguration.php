<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Configuration;

use Ecotone\Api\Dbal\DbalConfiguration;
use Ecotone\Api\ServiceContext;
use Ecotone\Api\Symfony\SymfonyConnectionReference;

final readonly class EcotoneConfiguration
{
    #[ServiceContext]
    public function databaseConnection(): SymfonyConnectionReference
    {
        return SymfonyConnectionReference::defaultManagerRegistry('default');
    }

    #[ServiceContext]
    public function dbalConfiguration(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()
            ->withDoctrineORMRepositories(true);
    }
}
