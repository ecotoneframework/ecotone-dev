<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Channel\Collector;

use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;

/**
 * licence Apache-2.0
 */
final class CollectorPauseInterceptor
{
    public function pauseCollecting(MethodInvocation $methodInvocation): mixed
    {
        CollectorStorage::pause();
        try {
            return $methodInvocation->proceed();
        } finally {
            CollectorStorage::resume();
        }
    }
}
