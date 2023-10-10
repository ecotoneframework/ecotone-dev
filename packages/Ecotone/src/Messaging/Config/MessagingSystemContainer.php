<?php

namespace Ecotone\Messaging\Config;

use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingConsumer\PollingConsumerContext;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\DistributedBus;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\QueryBus;
use Psr\Container\ContainerInterface;

class MessagingSystemContainer implements ConfiguredMessagingSystem
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function getGatewayByName(string $gatewayReferenceName): object
    {
        return $this->container->get($gatewayReferenceName);
    }

    public function getNonProxyGatewayByName(string $gatewayReferenceName): NonProxyCombinedGateway
    {
        return $this->container->get($gatewayReferenceName.'.nonProxy');
    }

    public function runConsoleCommand(string $commandName, array $parameters): mixed
    {
        return null;
    }

    public function getCommandBus(): CommandBus
    {
        return $this->container->get(CommandBus::class);
    }

    public function getQueryBus(): QueryBus
    {
        return $this->container->get(QueryBus::class);
    }

    public function getEventBus(): EventBus
    {
        return $this->container->get(EventBus::class);
    }

    public function getDistributedBus(): DistributedBus
    {
        return $this->container->get(DistributedBus::class);
    }

    public function getMessagePublisher(string $referenceName = MessagePublisher::class): MessagePublisher
    {
        return $this->container->get($referenceName);
    }

    public function getServiceFromContainer(string $referenceName): object
    {
        return $this->container->get($referenceName);
    }

    public function getMessageChannelByName(string $channelName): MessageChannel
    {
        return $this->container->get(new ChannelReference($channelName));
    }

    public function run(string $endpointId, ?ExecutionPollingMetadata $executionPollingMetadata = null): void
    {
        $pollingMetadata = $this->getPollingMetadata($endpointId, $executionPollingMetadata);
        $pollingConsumerContext = $this->container->get(PollingConsumerContext::class);
        $consumer = $this->container->get('polling.'.$endpointId.'.runner');
        $pollingConsumerContext->setPollingMetadate($pollingMetadata);
        try {
            $consumer->run();
        } finally {
            $pollingConsumerContext->setPollingMetadate(null);
        }
    }

    public function list(): array
    {
        // TODO: Implement list() method.
    }

    public function replaceWith(ConfiguredMessagingSystem $messagingSystem): void
    {
        Assert::isTrue($messagingSystem instanceof MessagingSystemContainer, 'Can only replace with ' . self::class);

        $this->container = $messagingSystem->container;
    }

    private function getPollingMetadata(string $endpointId, ?ExecutionPollingMetadata $executionPollingMetadata = null): PollingMetadata
    {
        return $this->container->get($endpointId.'.pollingMetadata')->applyExecutionPollingMetadata($executionPollingMetadata);
    }

    public function getGatewayList(): array
    {
        return [];
    }
}
