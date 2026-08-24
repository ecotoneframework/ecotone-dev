<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\Interceptor;

use Ecotone\Api\After;
use Ecotone\Api\Before;
use Ecotone\Api\ClassReference;
use Ecotone\Api\Header;
use Ecotone\Api\Payload;

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
