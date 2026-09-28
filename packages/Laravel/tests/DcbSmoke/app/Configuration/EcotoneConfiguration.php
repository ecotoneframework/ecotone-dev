<?php

declare(strict_types=1);

namespace App\DcbSmoke\Laravel\Configuration;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\EventSourcing\DynamicConsistencyBoundaryConfiguration;
use Ecotone\Api\EventSourcing\EventSourcingConfiguration;

/**
 * licence Enterprise
 */
final class EcotoneConfiguration
{
    #[ServiceContext]
    public function eventSourcing(): EventSourcingConfiguration
    {
        return EventSourcingConfiguration::createWithDefaults();
    }

    #[ServiceContext]
    public function dynamicConsistencyBoundary(): DynamicConsistencyBoundaryConfiguration
    {
        return DynamicConsistencyBoundaryConfiguration::createWithDefaults();
    }

    #[ServiceContext]
    public function dbal(): DbalConfiguration
    {
        return DbalConfiguration::createWithDefaults()
            ->withAutomaticTableInitialization(true);
    }
}
