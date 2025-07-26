<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeduplicationCommandBus;

use Ecotone\Messaging\Attribute\Deduplicated;
use Ecotone\Modelling\CommandBus;

/**
 * licence Apache-2.0
 */
#[Deduplicated(expression: "headers['orderId']")]
interface ExpressionDeduplicatedCommandBus extends CommandBus
{
}
