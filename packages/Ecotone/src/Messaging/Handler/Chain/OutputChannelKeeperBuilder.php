<?php

namespace Ecotone\Messaging\Handler\Chain;

use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\MessageHandler;
use LogicException;
use Ramsey\Uuid\Uuid;

class OutputChannelKeeperBuilder extends InputOutputMessageHandlerBuilder
{
    private GatewayProxyBuilder $keeperGateway;

    public function __construct(string $keptChannelName)
    {
        $this->keeperGateway = GatewayProxyBuilder::create('', KeeperGateway::class, 'execute', $keptChannelName);
    }

    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $interfaceToCallRegistry->getFor(OutputChannelKeeper::class, 'keep');
    }


    public function compile(ContainerMessagingBuilder $builder): Definition
    {
        $gateway = $this->keeperGateway->compile($builder);
        return ServiceActivatorBuilder::createWithDefinition(new Definition(OutputChannelKeeper::class, [$gateway]), 'keep')
            ->compile($builder);
    }
}
