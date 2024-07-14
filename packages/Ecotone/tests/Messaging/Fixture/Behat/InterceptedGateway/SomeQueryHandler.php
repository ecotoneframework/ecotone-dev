<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Behat\InterceptedGateway;

use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
class SomeQueryHandler
{
    public const CALCULATE = 'calculate';

    #[QueryHandler(SomeQueryHandler::CALCULATE)]
    public function calculate(int $sum): int
    {
        return $sum;
    }
}
