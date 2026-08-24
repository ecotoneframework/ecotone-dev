<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ProductsProjection;

use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceContext;
use Ecotone\EventSourcing\ProjectionRunningConfiguration;

/**
 * licence Apache-2.0
 */
final class ProductsConfiguration
{
    #[ServiceContext]
    public function setMaximumOneRunForProjections(): PollingMetadata
    {
        return PollingMetadata::create(Products::PROJECTION_NAME)
            ->setExecutionAmountLimit(3)
            ->setExecutionTimeLimitInMilliseconds(300);
    }

    #[ServiceContext]
    public function enablePollingProjection(): ProjectionRunningConfiguration
    {
        return ProjectionRunningConfiguration::createPolling(Products::PROJECTION_NAME);
    }
}
