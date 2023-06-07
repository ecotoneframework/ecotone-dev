<?php

namespace Ecotone\Messaging\Config;

use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Channel\ChannelInterceptorBuilder;
use Ecotone\Messaging\Channel\EventDrivenChannelInterceptorAdapter;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Channel\PollableChannelInterceptorAdapter;
use Ecotone\Messaging\Endpoint\ChannelAdapterConsumerBuilder;
use Ecotone\Messaging\Endpoint\ConsumerLifecycle;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;
use Ecotone\Messaging\Endpoint\InboundChannelAdapter\InboundChannelAdapterBuilder;
use Ecotone\Messaging\Endpoint\MessageHandlerConsumerBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyAdapter;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ProxyFactory;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\ServiceProviderInterface;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Messaging\MessagingException;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\DistributedBus;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\QueryBus;
use Psr\Container\ContainerInterface;

/**
 * Class Application
 * @package Ecotone\Messaging\Config
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
final class MessagingSystem implements ConfiguredMessagingSystem
{
    public const CONSUMER_BUILDER = 'builder';
    public const CONSUMER_HANDLER = 'handler';
    public const EXECUTION = 'built';

    /**
     * Application constructor.
     * @param ConsumerLifecycle[] $eventDrivenConsumers
     * @param ContainerInterface $gatewayLocator
     * @param ContainerInterface $nonProxyCombinedGatewaysLocator
     * @param ConsoleCommandConfiguration[] $consoleCommands
     * @param ChannelResolver $channelResolver
     * @param PollingMetadata[] $pollingMetadataConfigurations
     */
    public function __construct(
        iterable     $eventDrivenConsumers,
        private ContainerInterface     $endpointConsumersLocator,
        private ContainerInterface     $gatewayLocator,
        private ContainerInterface     $nonProxyCombinedGatewaysLocator,
        private ChannelResolver        $channelResolver,
        private ReferenceSearchService $referenceSearchService,
        private array                  $pollingMetadataConfigurations,
        private array                  $consoleCommands
    ) {
    }

    public function replaceWith(ConfiguredMessagingSystem $messagingSystem): void
    {
        Assert::isTrue($messagingSystem instanceof MessagingSystem, 'Can only replace with ' . self::class);

        $this->endpointConsumersLocator = $messagingSystem->endpointConsumersLocator;
        $this->gatewayLocator = $messagingSystem->gatewayLocator;
        $this->nonProxyCombinedGatewaysLocator = $messagingSystem->nonProxyCombinedGatewaysLocator;
        $this->channelResolver = $messagingSystem->channelResolver;
        $this->referenceSearchService = $messagingSystem->referenceSearchService;
        $this->pollingMetadataConfigurations = $messagingSystem->pollingMetadataConfigurations;
        $this->consoleCommands = $messagingSystem->consoleCommands;
    }

    /**
     * @param ReferenceSearchService $referenceSearchService
     * @param MessageChannelBuilder[] $messageChannelBuilders
     * @param array<string, ChannelInterceptorBuilder[]> $messageChannelInterceptors
     * @param GatewayProxyBuilder[][] $gatewayBuilders
     * @param MessageHandlerConsumerBuilder[] $messageHandlerConsumerFactories
     * @param PollingMetadata[] $pollingMetadataConfigurations
     * @param MessageHandlerBuilder[] $messageHandlerBuilders
     * @param ChannelAdapterConsumerBuilder[] $channelAdapterConsumerBuilders
     * @param ConsoleCommandConfiguration[] $consoleCommands
     * @throws MessagingException
     */
    public static function createFrom(
        ReferenceSearchService $referenceSearchService,
        array                  $messageChannelBuilders,
        array $messageChannelInterceptors,
        array                  $gatewayBuilders,
        array $messageHandlerConsumerFactories,
        array                  $pollingMetadataConfigurations,
        array $messageHandlerBuilders,
        array $channelAdapterConsumerBuilders,
        array                  $consoleCommands
    ): MessagingSystem {
        $channelResolver = self::createChannelResolver($messageChannelInterceptors, $messageChannelBuilders, $referenceSearchService);

        [$gateways, $nonProxyGateways] = self::configureGateways($gatewayBuilders, $referenceSearchService, $channelResolver);

        $gatewayReferences = [];
        foreach ($gateways as $gateway) {
            $gatewayReferences[$gateway->getReferenceName()] = $gateway->getGateway();
            $referenceSearchService->registerReferencedObject($gateway->getReferenceName(), $gatewayReferences[$gateway->getReferenceName()]);
        }
        $referenceSearchService->registerReferencedObject(ChannelResolver::class, $channelResolver);

        $eventDrivenConsumers = [];
        $pollingConsumerBuilders = [];
        foreach ($messageHandlerBuilders as $messageHandlerBuilder) {
            Assert::keyExists($messageChannelBuilders, $messageHandlerBuilder->getInputMessageChannelName(), "Missing channel with name {$messageHandlerBuilder->getInputMessageChannelName()} for {$messageHandlerBuilder}");
            $messageChannel = $messageChannelBuilders[$messageHandlerBuilder->getInputMessageChannelName()];
            foreach ($messageHandlerConsumerFactories as $messageHandlerConsumerBuilder) {
                if ($messageHandlerConsumerBuilder->isSupporting($messageHandlerBuilder, $messageChannel)) {
                    if ($messageHandlerConsumerBuilder->isPollingConsumer()) {
                        $pollingConsumerBuilders[$messageHandlerBuilder->getEndpointId()] = function ($pollingMetadata) use ($channelResolver, $referenceSearchService, $messageHandlerBuilder, $messageHandlerConsumerBuilder) {
                            static $consumerLifecycle = null;
                            if ($consumerLifecycle) {
                                return $consumerLifecycle;
                            } else {
                                $consumerLifecycle = $messageHandlerConsumerBuilder->build(
                                    $channelResolver,
                                    $referenceSearchService,
                                    $messageHandlerBuilder,
                                    $pollingMetadata
                                );
                                return $consumerLifecycle;
                            }
                        };
                    } else {
                        $eventDrivenConsumers[] = $messageHandlerConsumerBuilder->build($channelResolver, $referenceSearchService, $messageHandlerBuilder, self::getPollingMetadata($messageHandlerBuilder->getEndpointId(), $pollingMetadataConfigurations));
                    }
                }
            }
        }

        $inboundChannelAdapterBuilders = [];
        foreach ($channelAdapterConsumerBuilders as $channelAdapterBuilder) {
            $endpointId = $channelAdapterBuilder->getEndpointId();
            $inboundChannelAdapterBuilders[$endpointId] = function ($pollingMetadata) use ($referenceSearchService, $channelResolver, $channelAdapterBuilder) {
                static $channelAdapter = null;
                if ($channelAdapter) {
                    return $channelAdapter;
                } else {
                    $channelAdapter = $channelAdapterBuilder->build(
                        $channelResolver,
                        $referenceSearchService,
                        $pollingMetadata
                    );
                    return $channelAdapter;
                }
            };
        }

        $gatewayLocator = InMemoryPSRContainer::createFromAssociativeArray($gatewayReferences);
        $nonProxyGatewaysLocator = InMemoryPSRContainer::createFromAssociativeArray($nonProxyGateways);
        $endpointConsumersLocator = InMemoryPSRContainer::createFromAssociativeArray(array_merge($pollingConsumerBuilders, $inboundChannelAdapterBuilders));

        foreach ($eventDrivenConsumers as $consumer) {
            $consumer->run();
        }

        return new self(
            $eventDrivenConsumers, // should remain an array
            $endpointConsumersLocator, // done
            $gatewayLocator, // done
            $nonProxyGatewaysLocator, // done
            $channelResolver, // todo
            $referenceSearchService, // passthrough
            $pollingMetadataConfigurations, // passthrough
            $consoleCommands,// passthrough
        );
    }

    /**
     * @param array<string, ChannelInterceptorBuilder[]> $channelInterceptorBuilders
     * @param MessageChannelBuilder[] $channelBuilders
     * @param ReferenceSearchService $referenceSearchService
     * @throws MessagingException
     */
    private static function createChannelResolver(array $channelInterceptorBuilders, array $channelBuilders, ReferenceSearchService $referenceSearchService): ChannelResolver
    {
        $channels = [];
        foreach ($channelBuilders as $channelsBuilder) {
            $messageChannel = $channelsBuilder->build($referenceSearchService);
            $interceptorsForChannel = array_map(fn ($channelInterceptorBuilder) => $channelInterceptorBuilder->build($referenceSearchService), $channelInterceptorBuilders[$channelsBuilder->getMessageChannelName()] ?? []);

            if ($messageChannel instanceof PollableChannel && $interceptorsForChannel) {
                $messageChannel = new PollableChannelInterceptorAdapter($messageChannel, $interceptorsForChannel);
            } elseif ($interceptorsForChannel) {
                $messageChannel = new EventDrivenChannelInterceptorAdapter($messageChannel, $interceptorsForChannel);
            }

            $channels[$channelsBuilder->getMessageChannelName()] = $messageChannel;
        }

        return new ContainerChannelResolver(InMemoryPSRContainer::createFromAssociativeArray($channels));
    }

    /**
     * @param GatewayProxyBuilder[][] $preparedGateways
     * @param ReferenceSearchService $referenceSearchService
     * @param ChannelResolver $channelResolver
     * @return array{0: GatewayReference[], 1: array<string, NonProxyCombinedGateway>}
     * @throws MessagingException
     */
    private static function configureGateways(array $preparedGateways, ReferenceSearchService $referenceSearchService, ChannelResolver $channelResolver): array
    {
        $gateways = [];
        $nonProxyCombinedGateways = [];
        /** @var ProxyFactory $proxyFactory */
        $proxyFactory = $referenceSearchService->get(ProxyFactory::REFERENCE_NAME);

        foreach ($preparedGateways as $referenceName => $preparedGatewaysForReference) {
            $referenceName = $preparedGatewaysForReference[0]->getReferenceName();
            $nonProxyCombinedGatewaysMethods = [];
            foreach ($preparedGatewaysForReference as $proxyBuilder) {
                $nonProxyCombinedGatewaysMethods[$proxyBuilder->getRelatedMethodName()] =
                    $proxyBuilder->buildWithoutProxyObject($referenceSearchService, $channelResolver);
            }

            $nonProxyCombinedGateways[$referenceName] = NonProxyCombinedGateway::createWith($referenceName, $nonProxyCombinedGatewaysMethods);
            $interfaceName = $preparedGatewaysForReference[0]->getInterfaceName();
            $proxyAdapter = new GatewayProxyAdapter($nonProxyCombinedGatewaysMethods);
            $gateways[$referenceName] =
                GatewayReference::createWith(
                    $referenceName,
                    $proxyFactory->createProxyClassWithAdapter($interfaceName, $proxyAdapter)
                );
        }
        return [$gateways, $nonProxyCombinedGateways];
    }

    private static function getPollingMetadata(string $endpointId, array $pollingMetadataConfigurations): PollingMetadata
    {
        return array_key_exists($endpointId, $pollingMetadataConfigurations) ? $pollingMetadataConfigurations[$endpointId] : PollingMetadata::create($endpointId);
    }

    public function run(string $name, ?ExecutionPollingMetadata $executionPollingMetadata = null): void
    {
        $pollingMetadata = self::getPollingMetadata($name, $this->pollingMetadataConfigurations)
            ->applyExecutionPollingMetadata($executionPollingMetadata);

        if (! $this->endpointConsumersLocator->has($name)) {
            throw InvalidArgumentException::create("Can't run `{$name}` as it does not exists. Please verify, if the name is correct using `ecotone:list`.");
        }

        $consumerFactory = $this->endpointConsumersLocator->get($name);
        $consumer = $consumerFactory($pollingMetadata);
        $consumer->run();
    }

    public function getServiceFromContainer(string $referenceName): object
    {
        Assert::isTrue($this->referenceSearchService->has($referenceName), "Service with reference {$referenceName} does not exists");

        return $this->referenceSearchService->get($referenceName);
    }

    /**
     * @inheritDoc
     */
    public function getGatewayByName(string $gatewayReferenceName): object
    {
        Assert::isTrue($this->gatewayLocator->has($gatewayReferenceName), "Gateway with reference {$gatewayReferenceName} does not exists");

        return $this->gatewayLocator->get($gatewayReferenceName);
    }

    public function getNonProxyGatewayByName(string $gatewayReferenceName): NonProxyCombinedGateway
    {
        Assert::isTrue($this->nonProxyCombinedGatewaysLocator->has($gatewayReferenceName), "Gateway with reference {$gatewayReferenceName} does not exists");

        return $this->nonProxyCombinedGatewaysLocator->get($gatewayReferenceName);
    }

    public function runConsoleCommand(string $commandName, array $parameters): mixed
    {
        $consoleCommandConfiguration = null;
        foreach ($this->consoleCommands as $consoleCommand) {
            if ($consoleCommand->getName() === $commandName) {
                $consoleCommandConfiguration = $consoleCommand;
            }
        }
        Assert::notNull($consoleCommandConfiguration, "Trying to run not existing console command {$commandName}");
        /** @var MessagingEntrypoint $gateway */
        $gateway = $this->getGatewayByName(MessagingEntrypoint::class);

        $arguments = [];

        foreach ($parameters as $argumentName => $value) {
            if (! $this->hasParameterWithGivenName($consoleCommandConfiguration, $argumentName)) {
                continue;
            }

            $arguments[$consoleCommandConfiguration->getHeaderNameForParameterName($argumentName)] = $value;
        }
        foreach ($consoleCommandConfiguration->getParameters() as $commandParameter) {
            if (! array_key_exists($consoleCommandConfiguration->getHeaderNameForParameterName($commandParameter->getName()), $arguments)) {
                if (! $commandParameter->hasDefaultValue()) {
                    throw InvalidArgumentException::create("Missing argument with name {$commandParameter->getName()} for console command {$commandName}");
                }

                $arguments[$consoleCommandConfiguration->getHeaderNameForParameterName($commandParameter->getName())] = $commandParameter->getDefaultValue();
            }
        }

        return $gateway->sendWithHeaders([], $arguments, $consoleCommandConfiguration->getChannelName());
    }

    /**
     * @inheritDoc
     */
    public function getGatewayList(): iterable
    {
        if ($this->gatewayLocator instanceof ServiceProviderInterface) {
            return $this->gatewayLocator->getProvidedServices();
        }
        return [];
    }

    public function getCommandBus(): CommandBus
    {
        return $this->getGatewayByName(CommandBus::class);
    }

    public function getQueryBus(): QueryBus
    {
        return $this->getGatewayByName(QueryBus::class);
    }

    public function getEventBus(): EventBus
    {
        return $this->getGatewayByName(EventBus::class);
    }

    public function getDistributedBus(): DistributedBus
    {
        return $this->getGatewayByName(DistributedBus::class);
    }

    public function getMessagePublisher(string $referenceName = MessagePublisher::class): MessagePublisher
    {
        return $this->getGatewayByName($referenceName);
    }

    /**
     * @inheritDoc
     */
    public function getMessageChannelByName(string $channelName): MessageChannel
    {
        return $this->channelResolver->resolve($channelName);
    }

    /**
     * @inheritDoc
     */
    public function list(): array
    {
        if ($this->endpointConsumersLocator instanceof ServiceProviderInterface) {
            return $this->endpointConsumersLocator->getProvidedServices();
        }
        return [];
    }

    /**
     * @param ConsoleCommandConfiguration|null $consoleCommandConfiguration
     * @param int|string $argumentName
     * @return bool
     */
    private function hasParameterWithGivenName(?ConsoleCommandConfiguration $consoleCommandConfiguration, int|string $argumentName): bool
    {
        foreach ($consoleCommandConfiguration->getParameters() as $commandParameter) {
            if ($commandParameter->getName() === $argumentName) {
                return true;
            }
        }

        return false;
    }
}
