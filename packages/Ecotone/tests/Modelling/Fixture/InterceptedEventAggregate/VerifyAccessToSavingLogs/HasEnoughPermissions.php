<?php

namespace Test\Ecotone\Modelling\Fixture\InterceptedEventAggregate\VerifyAccessToSavingLogs;

use Ecotone\Messaging\Attribute\Interceptor\Around;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use InvalidArgumentException;
use Test\Ecotone\Modelling\Fixture\InterceptedEventAggregate\Logger;

/**
 * licence Apache-2.0
 */
class HasEnoughPermissions
{
    #[Around(pointcut: ValidateExecutor::class)]
    public function validate(MethodInvocation $methodInvocation, ?Logger $logger)
    {
        if (is_null($logger)) {
            return $methodInvocation->proceed();
        }

        $data = $methodInvocation->getArguments()[0];

        if (! $logger->hasAccess($data['executorId'])) {
            throw new InvalidArgumentException('Not enough permissions');
        }

        return $methodInvocation->proceed();
    }
}
