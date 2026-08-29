<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Annotation\MessageEndpoint\ServiceActivator\WithLogger;

use Ecotone\Api\LogAfter;
use Ecotone\Api\LogBefore;
use Ecotone\Api\LogError;
use Ecotone\Api\ServiceActivator;
use Ecotone\Messaging\Handler\Logger\LoggingLevel;

/**
 * licence Apache-2.0
 */
class ServiceActivatorWithLoggerExample
{
    #[ServiceActivator('inputChannel', 'test-name')]
    #[
        LogBefore(LoggingLevel::INFO, true),
        LogAfter(LoggingLevel::INFO, true),
        LogError(LoggingLevel::CRITICAL, true)
    ]
    public function sendMessage(): void
    {
    }
}
