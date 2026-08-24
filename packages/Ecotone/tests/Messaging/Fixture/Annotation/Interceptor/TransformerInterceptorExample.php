<?php

namespace Test\Ecotone\Messaging\Fixture\Annotation\Interceptor;

use Ecotone\Api\After;
use Ecotone\Api\Before;
use Ecotone\Api\ClassReference;
use Ecotone\Api\Header;
use Ecotone\Api\Payload;
use Ecotone\Api\Presend;

#[ClassReference('someMethodInterceptor')]
/**
 * licence Apache-2.0
 */
class TransformerInterceptorExample
{
    #[Before(2, ServiceActivatorInterceptorExample::class, true)]
    public function doSomethingBefore(#[Payload] string $name, #[Header('surname')] string $surname): void
    {
    }

    #[After(changeHeaders: true)]
    public function doSomethingAfter(#[Payload] string $name, #[Header('surname')] string $surname): void
    {
    }

    #[Presend(2, ServiceActivatorInterceptorExample::class, true)]
    public function beforeSend(#[Payload] string $name, #[Header('surname')] string $surname): void
    {
    }
}
