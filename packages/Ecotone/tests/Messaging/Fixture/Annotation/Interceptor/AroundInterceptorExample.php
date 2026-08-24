<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Annotation\Interceptor;

use Ecotone\Api\Around;
use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Header;
use Ecotone\Api\Payload;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use stdClass;

final class AroundInterceptorExample
{
    public ?object $payload = null;
    public ?string $consumerName = null;

    #[Asynchronous('async')]
    #[CommandHandler(routingKey: 'doSomethingAsync', endpointId: 'doSomethingAsync.endpoint')]
    public function doSomethingAsync(stdClass $command): void
    {

    }

    #[Around(pointcut: AsynchronousRunningEndpoint::class)]
    public function intercept(MethodInvocation $methodInvocation, #[Header('polledChannelName')] string $consumerName, #[Payload] stdClass $command)
    {
        $this->payload = $command;
        $this->consumerName = $consumerName;

        return $methodInvocation->proceed();
    }
}
