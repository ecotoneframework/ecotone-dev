<?php

namespace Ecotone\Test;

use Ecotone\AnnotationFinder\FileSystem\FileSystemAnnotationFinder;
use Ecotone\Lite\InMemoryContainerImplementation;
use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Lite\PhpDiContainerImplementation;
use Ecotone\Lite\Test\FlowTestSupport;
use Ecotone\Lite\Test\MessagingTestSupport;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Compiler\RegisterInterfaceToCallReferences;
use Ecotone\Messaging\Config\Container\Compiler\RegisterSingletonMessagingServices;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\EndpointRunnerReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\ProxyBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\MessagingSystemConfiguration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ServiceCacheConfiguration;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Messaging\ConfigurationVariableService;
use Ecotone\Messaging\Conversion\AutoCollectionConversionService;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\ChannelAdapterConsumerBuilder;
use Ecotone\Messaging\Endpoint\EndpointRunner;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\MessageHandlerConsumerBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Gateway\StorageMessagingEntrypoint;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Logger\StubLoggingGateway;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;

use Ecotone\Messaging\InMemoryConfigurationVariableService;

use Ecotone\Messaging\MessageChannel;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\QueryBus;
use Ecotone\SymfonyBundle\DependencyInjection\SymfonyContainerAdapter;
use function get_class;

use Ramsey\Uuid\Uuid;

class ComponentTestBuilder
{
    private function __construct(private InMemoryPSRContainer $container, private MessagingSystemConfiguration $messagingSystemConfiguration)
    {
    }

    public static function create(
        array $classesToResolve = [],
        ?ServiceConfiguration $configuration = null
    ): self
    {
        // This will be used when symlinks to Ecotone packages are used (e.g. Split Testing - Github Actions)
        $debug = debug_backtrace();
        $path = dirname(array_pop($debug)['file']);
        $pathToRootCatalog = FileSystemAnnotationFinder::getRealRootCatalog($path, $path);

        FileSystemAnnotationFinder::getRealRootCatalog($pathToRootCatalog, $pathToRootCatalog);

        return new self(
            InMemoryPSRContainer::createFromAssociativeArray([
                ServiceCacheConfiguration::REFERENCE_NAME => ServiceCacheConfiguration::noCache()
            ]),
            MessagingSystemConfiguration::prepare(
                $pathToRootCatalog,
                InMemoryConfigurationVariableService::createEmpty(),
                $configuration ?? ServiceConfiguration::createWithDefaults()->withSkippedModulePackageNames(ModulePackageList::allPackages()),
                $classesToResolve,
                true,
            )
        );
    }

    public function withChannel(MessageChannelBuilder $channelBuilder): self
    {
        $this->messagingSystemConfiguration->registerMessageChannel($channelBuilder);

        return $this;
    }

    public function withConverter(CompilableBuilder $converter): self
    {
        $this->messagingSystemConfiguration->registerConverter($converter);

        return $this;
    }

    public function withConverters(array $converters): self
    {
        foreach ($converters as $converter) {
            $this->withConverter($converter);
        }

        return $this;
    }

    public function withPollingMetadata(PollingMetadata $pollingMetadata): self
    {
        $this->messagingSystemConfiguration->registerPollingMetadata($pollingMetadata);

        return $this;
    }

    public function withReference(string $referenceName, object $object): self
    {
        $this->container->set($referenceName, $object);

        return $this;
    }

    public function withMessageHandler(MessageHandlerBuilder $messageHandlerBuilder): self
    {
        $this->messagingSystemConfiguration->registerMessageHandler($messageHandlerBuilder);

        return $this;
    }

    public function build(): FlowTestSupport
    {
        $containerBuilder = new \Ecotone\Messaging\Config\Container\ContainerBuilder();
        $containerBuilder->addCompilerPass($this->messagingSystemConfiguration);
        $containerBuilder->addCompilerPass(new RegisterInterfaceToCallReferences());
        $containerBuilder->addCompilerPass(new InMemoryContainerImplementation($this->container));
        $containerBuilder->compile();

        /** @var ConfiguredMessagingSystem $configuredMessagingSystem */
        $configuredMessagingSystem = $this->container->get(ConfiguredMessagingSystem::class);

        return new FlowTestSupport(
            $configuredMessagingSystem->getGatewayByName(CommandBus::class),
            $configuredMessagingSystem->getGatewayByName(EventBus::class),
            $configuredMessagingSystem->getGatewayByName(QueryBus::class),
            $configuredMessagingSystem->getGatewayByName(MessagingTestSupport::class),
            $configuredMessagingSystem->getGatewayByName(MessagingEntrypoint::class),
            $configuredMessagingSystem
        );
    }

    public function buildWithProxy(GatewayProxyBuilder $gatewayProxy): mixed
    {
        $this->messagingSystemConfiguration->registerGatewayBuilder($gatewayProxy);

        $this->compile();
        return $this->container->get($gatewayProxy->getReferenceName());
    }

    public function withRegisteredMessageHandlerConsumer(MessageHandlerConsumerBuilder $messageHandlerConsumerBuilder, MessageHandlerBuilder $messageHandlerBuilder): self
    {
        $messageHandlerConsumerBuilder->registerConsumer($this->messagingBuilder, $messageHandlerBuilder);

        return $this;
    }

    public function withRegisteredChannelAdapter(ChannelAdapterConsumerBuilder $channelAdapterConsumerBuilder): self
    {
        $channelAdapterConsumerBuilder->registerConsumer($this->messagingBuilder);

        return $this;
    }

    public function getEndpointRunner(string $endpointId): EndpointRunner
    {
        return $this->container->get(new EndpointRunnerReference($endpointId));
    }

    public function runEndpoint(string $endpointId, ?ExecutionPollingMetadata $executionPollingMetadata = null): void
    {
        $this->getEndpointRunner($endpointId)->runEndpointWithExecutionPollingMetadata($executionPollingMetadata);
    }

    public function getGatewayByName(string $name)
    {
        return $this->container->get($name);
    }
}
