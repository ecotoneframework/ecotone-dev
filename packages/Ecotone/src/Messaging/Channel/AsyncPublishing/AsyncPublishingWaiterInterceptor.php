<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\AsyncPublishing;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;

/**
 * licence Enterprise
 */
final class AsyncPublishingWaiterInterceptor
{
    public function __construct(private AsyncPublishingRegistry $asyncPublishingRegistry)
    {
    }

    public function await(MethodInvocation $methodInvocation): mixed
    {
        if ($this->asyncPublishingRegistry->isScopeActive()) {
            return $methodInvocation->proceed();
        }

        $this->asyncPublishingRegistry->openScope();
        try {
            $result = $methodInvocation->proceed();

            $deliveryResult = $this->asyncPublishingRegistry->awaitAll();
            if (! $deliveryResult->isSuccessful()) {
                throw AsyncPublishingFailedException::withFailedDeliveries($deliveryResult->getFailedDeliveries());
            }
        } finally {
            $this->asyncPublishingRegistry->closeScope();
        }

        return $result;
    }
}
