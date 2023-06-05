<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ProductsProjection;

use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;

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
