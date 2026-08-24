<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\Interceptor;

use Ecotone\Api\Attribute\ClassReference;
use Ecotone\Api\Attribute\Interceptor\After;
use Ecotone\Api\Attribute\Interceptor\Before;
use Ecotone\Api\Attribute\Parameter\Header;
use Ecotone\Api\Attribute\Parameter\Payload;

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
