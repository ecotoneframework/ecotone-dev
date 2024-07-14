<?php

namespace Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor;

use Ecotone\Messaging\Attribute\Interceptor\Around;

/**
 * Class AspectWithoutMethodInterceptorExample
 * @package Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor
 * @author Dariusz Gafka <support@simplycodedsoftware.com>
 */
/**
 * licence Apache-2.0
 */
class AspectWithoutMethodInterceptorExample
{
    #[Around]
    public function doSomething(): void
    {
    }
}
