<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint;

use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\PollingMetadataReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Endpoint\PollingConsumer\InterceptedConsumerRunner;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
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
        return $this->inboundGateway->compile($builder);
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
}
