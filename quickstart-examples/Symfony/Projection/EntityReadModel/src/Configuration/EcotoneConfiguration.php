<?php

/*
 * licence Apache-2.0
 */

declare(strict_types=1);

namespace App\Configuration;

use Ecotone\Dbal\Api\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\SymfonyBundle\Api\ExtensionObject\SymfonyConnectionReference;

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
