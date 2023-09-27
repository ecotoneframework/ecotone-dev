<?php

namespace App\Domain\Customer;

use Ecotone\Messaging\Attribute\Interceptor\Around;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;

class IsOwnerVerificator
{
    #[Around(pointcut: IsOwnedByExecutor::class)]
    public function isOwner(MethodInvocation $methodInvocation, Customer $customer, #[Headers] array $metadata)
    {
        $customerEmail = (string) $customer->getEmail();
        if (isset($metadata["executorEmail"]) && $customerEmail === $metadata["executorEmail"]) {
            return $methodInvocation->proceed();
        } else {
            throw new \InvalidArgumentException("No access to do this action!");
        }
    }
}