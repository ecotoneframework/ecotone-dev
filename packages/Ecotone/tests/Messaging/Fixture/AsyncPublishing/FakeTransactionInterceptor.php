<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\AsyncPublishing;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Throwable;

/**
 * licence Apache-2.0
 */
final class FakeTransactionInterceptor
{
    public function __construct(private OperationsLog $operationsLog)
    {
    }

    public function transactional(MethodInvocation $methodInvocation): mixed
    {
        $this->operationsLog->log('transaction started');
        try {
            $result = $methodInvocation->proceed();
            $this->operationsLog->log('transaction committed');

            return $result;
        } catch (Throwable $exception) {
            $this->operationsLog->log('transaction rolled back');

            throw $exception;
        }
    }
}
