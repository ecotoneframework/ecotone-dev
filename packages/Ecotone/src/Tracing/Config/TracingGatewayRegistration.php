<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Config;

use Ecotone\Api\Tracing\TracingGateway;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Tracing\StoredTracingGateway;

/**
 * licence Apache-2.0
 */
final class TracingGatewayRegistration
{
    public static function register(Configuration $messagingConfiguration, string $serviceClass, Definition $service): void
    {
        $messagingConfiguration->registerServiceDefinition($serviceClass, $service);
        foreach ([
            'isCompleted' => [GatewayPayloadBuilder::create('tracingId')],
            'isCompletedFor' => [GatewayPayloadBuilder::create('tracingId'), GatewayHeaderBuilder::create('destination', StoredTracingGateway::DESTINATION_HEADER)],
            'await' => [GatewayPayloadBuilder::create('tracingId'), GatewayHeaderBuilder::create('timeout', StoredTracingGateway::TIMEOUT_HEADER)],
            'awaitFor' => [GatewayPayloadBuilder::create('tracingId'), GatewayHeaderBuilder::create('destination', StoredTracingGateway::DESTINATION_HEADER), GatewayHeaderBuilder::create('timeout', StoredTracingGateway::TIMEOUT_HEADER)],
        ] as $method => $parameterConverters) {
            $channel = 'ecotone.tracing.completion.' . $method;
            $messagingConfiguration
                ->registerMessageHandler(MessageProcessorActivatorBuilder::createForMethod($serviceClass, $method)->withInputChannelName($channel))
                ->registerGatewayBuilder(GatewayProxyBuilder::create(TracingGateway::class, TracingGateway::class, $method, $channel)->withParameterConverters($parameterConverters));
        }
    }
}
