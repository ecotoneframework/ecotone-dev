<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Annotation\Interceptor;

use Ecotone\Api\Around;
use Ecotone\Api\Header;
use Ecotone\Api\Headers;
use Ecotone\Api\Payload;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use stdClass;

/**
 * licence Apache-2.0
 */
class AroundInterceptorWithCustomParameterConverters
{
    private bool $wasCalled = false;

    #[Around(pointcut: self::class)]
    public function handle(MethodInvocation $methodInvocation, #[Header('token')] int $token, #[Payload] stdClass $payload, #[Headers] array $headers)
    {
        $this->wasCalled = true;
        return $methodInvocation->proceed();
    }

    public function wasCalled(): bool
    {
        return $this->wasCalled;
    }
}
