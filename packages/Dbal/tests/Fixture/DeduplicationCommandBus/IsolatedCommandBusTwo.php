<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeduplicationCommandBus;

use Ecotone\Api\CommandBus;
use Ecotone\Api\Deduplicated;

/**
 * licence Apache-2.0
 */
#[Deduplicated(expression: "headers['orderId']", trackingName: 'bus_two')]
interface IsolatedCommandBusTwo extends CommandBus
{
}
