<?php

namespace Test\Ecotone\Messaging\Fixture\Handler\Processor\Interceptor;

use Ecotone\Api\ClassReference;

#[ClassReference('methodInterceptor')]
/**
 * licence Apache-2.0
 */
class MethodInterceptorWithoutAspectExample
{
    public function doSomething(): void
    {
    }
}
