<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\PollingMetadataReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Endpoint\PollingConsumer\InterceptedConsumerRunner;
use Ecotone\Messaging\Endpoint\PollingConsumer\PollingConsumerErrorChannelInterceptor;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Precedence;
use Ecotone\Messaging\Scheduling\Clock;
use Psr\Log\LoggerInterface;

/**
 * Class InterceptedConsumerBuilder
 * @package Ecotone\Messaging\Endpoint
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
abstract class InterceptedChannelAdapterBuilder implements ChannelAdapterConsumerBuilder, CompilableBuilder
{
    protected ?string $endpointId = null;
    protected InboundChannelAdapterEntrypoint|GatewayProxyBuilder $inboundGateway;

    protected function withContinuesPolling(): bool
    {
        return true;
    }

    protected function compileGateway(ContainerMessagingBuilder $builder): Definition|Reference|DefinedObject
    {
        return $this->inboundGateway
            ->addAroundInterceptor($this->getErrorInterceptorReference($builder))
            ->compile($builder);
    }

    public function registerConsumer(ContainerMessagingBuilder $builder): void
    {
        $messagePoller = $this->compile($builder);
        $gateway = $this->compileGateway($builder);
        $consumerRunner = new Definition(InterceptedConsumerRunner::class, [
            $gateway,
            $messagePoller,
            new PollingMetadataReference($this->endpointId),
            new Reference(Clock::class),
            new Reference(LoggerInterface::class),
        ]);
        $builder->registerPollingEndpoint($this->endpointId, $consumerRunner);
    }

    private function getErrorInterceptorReference(ContainerMessagingBuilder $builder): AroundInterceptorReference
    {
        if (! $builder->has(PollingConsumerErrorChannelInterceptor::class)) {
            $builder->register(PollingConsumerErrorChannelInterceptor::class, new Definition(PollingConsumerErrorChannelInterceptor::class, [
                new Reference(ChannelResolver::class),
            ]));
        }
        return AroundInterceptorReference::create(
            PollingConsumerErrorChannelInterceptor::class,
            $builder->getInterfaceToCall(new InterfaceToCallReference(PollingConsumerErrorChannelInterceptor::class, 'handle')),
            Precedence::ERROR_CHANNEL_PRECEDENCE,
        );
    }
}
