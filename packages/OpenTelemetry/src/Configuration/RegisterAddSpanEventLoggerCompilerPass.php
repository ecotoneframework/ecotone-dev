<?php

/*
 * licence Apache-2.0
 */

namespace Ecotone\OpenTelemetry\Configuration;

use Ecotone\Messaging\Config\Container\Compiler\CompilerPass;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Logger\SubscribableLoggingGateway;
use Ecotone\OpenTelemetry\AddSpanEventLogger;

class RegisterAddSpanEventLoggerCompilerPass implements CompilerPass
{
    public function process(ContainerBuilder $builder): void
    {
        if ($builder->has(LoggingGateway::class)) {
            $loggingGatewayDefinition = $builder->getDefinition(LoggingGateway::class);

            if (is_a($loggingGatewayDefinition->getClassName(), SubscribableLoggingGateway::class, true)) {
                $loggingGatewayDefinition->addMethodCall('registerLogger', [new Definition(AddSpanEventLogger::class)]);
            }
        }
    }
}
