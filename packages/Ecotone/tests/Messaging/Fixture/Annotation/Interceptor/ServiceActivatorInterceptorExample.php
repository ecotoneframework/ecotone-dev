<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\Interceptor;

use Ecotone\Messaging\Attribute\ClassReference;
use Ecotone\Messaging\Attribute\Interceptor\After;
use Ecotone\Messaging\Attribute\Interceptor\Before;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Payload;

#[ClassReference('someMethodInterceptor')]
/**
 * licence Apache-2.0
 */
class ServiceActivatorInterceptorExample
{
    #[Before(2, ServiceActivatorInterceptorExample::class)]
    public function doSomethingBefore(#[Payload] string $name, #[Header('surname')] string $surname): void
    {
    }

    #[After]
    public function doSomethingAfter(#[Payload] string $name, #[Header('surname')] string $surname): void
    {
    }
}
