<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\InterceptedBridge;

use Ecotone\Messaging\Attribute\Interceptor\Around;
use Ecotone\Messaging\Attribute\ServiceActivator;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvocation;

/**
 * licence Apache-2.0
 */
final class BridgeExampleIncomplete
{
    #[ServiceActivator('bridgeExample', outputChannelName: 'bridgeSum')]
    public function result(int $result): int
    {
        return $result;
    }
}
