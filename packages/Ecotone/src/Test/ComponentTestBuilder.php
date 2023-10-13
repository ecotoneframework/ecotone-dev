<?php

namespace Ecotone\Test;

use Ecotone\Lite\InMemoryContainerImplementation;
use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\Config\Container\Compiler\RegisterSingletonMessagingServices;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\AutoCollectionConversionService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\PollingConsumer\PollingConsumerContext;
use Ecotone\Messaging\Endpoint\PollingConsumer\PollingConsumerContextProvider;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ramsey\Uuid\Uuid;

class ComponentTestBuilder
{
    private ContainerMessagingBuilder $messagingBuilder;

    public function __construct(private InMemoryPSRContainer $container, private ContainerBuilder $builder)
    {
        $this->messagingBuilder = new ContainerMessagingBuilder($builder);
    }

    public static function create(): self
    {
        $container = InMemoryPSRContainer::createEmpty();
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addCompilerPass(new RegisterSingletonMessagingServices());
        $containerBuilder->addCompilerPass(new RegisterInterfaceToCallReferences());
        $containerBuilder->addCompilerPass(new InMemoryContainerImplementation($container));
        return new self($container, $containerBuilder);
    }

    public function withChannel(string $channelName, DefinedObject $channel): self
    {
        $this->messagingBuilder->register(new ChannelReference($channelName), $channel);

        return $this;
    }

    public function withPollingMetadata(PollingMetadata $pollingMetadata): self
    {
        $this->messagingBuilder->register("polling.{$pollingMetadata->getEndpointId()}.metadata", $pollingMetadata);

        return $this;
    }

    public function withReference(string $referenceName, object $object): self
    {
        $this->container->set($referenceName, $object);

        return $this;
    }

    public function build(CompilableBuilder $compilableBuilder): mixed
    {
        $reference = $compilableBuilder->compile($this->messagingBuilder);
        if ($reference instanceof Definition) {
            $id = Uuid::uuid4();
            $this->builder->register($id, $reference);
            $referenceToReturn = new Reference($id);
        } else {
            $referenceToReturn = $reference;
        }

        $this->compile();
        return $this->container->get($referenceToReturn->getId());
    }

    public function runEndpoint(string $endpointId, ?ExecutionPollingMetadata $executionPollingMetadata = null): void
    {
        /** @var PollingConsumerContextProvider $pollingEndpointRunner */
        $pollingEndpointRunner = $this->container->get(PollingConsumerContext::class);
        $pollingEndpointRunner->runEndpointWithExecutionPollingMetadata($endpointId, $executionPollingMetadata);
    }

    private function compile(): void
    {
        if (! $this->builder->has(ConversionService::REFERENCE_NAME)) {
            $this->builder->register(ConversionService::REFERENCE_NAME, new Definition(AutoCollectionConversionService::class, ['converters' => []], 'createWith'));
        }
        $this->builder->compile();
    }
}