<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Behat\Calculating;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;

/**
 * licence Apache-2.0
 */
class CalculatingConfiguration
{
    #[ServiceContext]
    public function registerMetadata()
    {
        return PollingMetadata::create('inboundCalculator')
            ->setHandledMessageLimit(1);
    }
}
