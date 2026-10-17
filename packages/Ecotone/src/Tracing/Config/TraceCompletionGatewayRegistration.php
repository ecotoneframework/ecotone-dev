<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Config;

use Ecotone\Api\Tracing\TraceCompletion;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Tracing\StoredTraceCompletion;

/**
 * licence Apache-2.0
 */
final class TraceCompletionGatewayRegistration
{
    public static function register(Configuration $messagingConfiguration, string $serviceClass, Definition $service): void
    {
        $messagingConfiguration->registerServiceDefinition($serviceClass, $service);
        foreach ([
            'isCompleted' => [GatewayPayloadBuilder::create('traceId')],
            'isCompletedFor' => [GatewayPayloadBuilder::create('traceId'), GatewayHeaderBuilder::create('destination', StoredTraceCompletion::DESTINATION_HEADER)],
            'await' => [GatewayPayloadBuilder::create('traceId'), GatewayHeaderBuilder::create('timeout', StoredTraceCompletion::TIMEOUT_HEADER)],
            'awaitFor' => [GatewayPayloadBuilder::create('traceId'), GatewayHeaderBuilder::create('destination', StoredTraceCompletion::DESTINATION_HEADER), GatewayHeaderBuilder::create('timeout', StoredTraceCompletion::TIMEOUT_HEADER)],
        ] as $method => $parameterConverters) {
            $channel = 'ecotone.tracing.completion.' . $method;
            $messagingConfiguration
                ->registerMessageHandler(MessageProcessorActivatorBuilder::createForMethod($serviceClass, $method)->withInputChannelName($channel))
                ->registerGatewayBuilder(GatewayProxyBuilder::create(TraceCompletion::class, TraceCompletion::class, $method, $channel)->withParameterConverters($parameterConverters));
        }
    }
}
