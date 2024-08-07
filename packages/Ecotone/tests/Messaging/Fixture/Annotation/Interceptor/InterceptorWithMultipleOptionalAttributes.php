<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\Interceptor;

use Test\Ecotone\Messaging\Fixture\Behat\Calculating\AfterMultiplyCalculation;
use Test\Ecotone\Messaging\Fixture\Behat\Calculating\BeforeMultiplyCalculation;
use Test\Ecotone\Messaging\Fixture\Behat\Calculating\PowerCalculation;

/**
 * licence Apache-2.0
 */
class InterceptorWithMultipleOptionalAttributes
{
    public function doSomething(?BeforeMultiplyCalculation $beforeMultiplyCalculation, ?AfterMultiplyCalculation $afterMultiplyCalculation, ?PowerCalculation $powerCalculation): void
    {
    }
}
