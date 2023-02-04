<?php

namespace Ecotone\Modelling\Config\InstantRetry;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Attribute\Deduplicated;
use Ecotone\Messaging\Attribute\IdentifiedAnnotation;
use Ecotone\Messaging\Attribute\InstantlyRetried;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Scheduling\Clock;
use Enqueue\Dbal\DbalContext;
use Interop\Queue\ConnectionFactory;
use Interop\Queue\Exception\Exception;

class InstantRetryInterceptor
{
    public function __construct(private int $maxRetryAttempts, private array $exceptions = []) {}

    public function retry(MethodInvocation $methodInvocation)
    {
        $isSuccessful = false;
        $retries = 0;

        $result = null;
        while (!$isSuccessful) {
            try {
                $result = $methodInvocation->proceed();
                $isSuccessful = true;
            }catch (\Exception $exception) {
                if (!$this->canRetryThrownException($exception) || $retries >= $this->maxRetryAttempts) {
                    throw $exception;
                }

                $retries++;
            }
        }

        return $result;
    }

    private function canRetryThrownException(\Exception $thrownException): bool
    {
        if ($this->exceptions === []) {
            return true;
        }

        foreach ($this->exceptions as $exception) {
            if (TypeDescriptor::createFromVariable($thrownException)->isCompatibleWith(TypeDescriptor::create($exception))) {
                return true;
            }
        }

        return false;
    }
}
