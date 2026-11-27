<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Ecotone\AnnotationFinder\AnnotatedFinding;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\DelayedRetry;
use Ecotone\Api\Attribute\ErrorChannel;
use Ecotone\Api\Attribute\IgnorePayload;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\PartitionDeadLetter;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\ExtensionObject\PartitionDeadLetterConfiguration;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\Dbal\Gateway\PartitionDeadLetterGateway;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Pulse\PulseConfiguration;
use Ecotone\Dbal\Database\DeadLetterTableManager;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Dbal\Recoverability\AnyDeadLetterReplayOrder;
use Ecotone\Dbal\Recoverability\DeadLetterHandler;
use Ecotone\Dbal\Recoverability\DeadLetterReplayOrder;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\EventSourcing\Mapping\EventMapper;
use Ecotone\Messaging\Attribute\EndpointAnnotation;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ConsoleCommandModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\AttributeDeclaration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\PollingConsumer\AsyncEndpointAnnotationContext;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionExecutorCompiler;
use Ecotone\Messaging\Handler\Gateway\ErrorChannelService;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\HeaderBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadConverter;
use Ecotone\Messaging\Handler\Recoverability\DeadLetterChannels;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseSnapshotSectionRegistration;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class PartitionDeadLetterRegistration
{
    public const UNDIVIDED_STORE_SUFFIX = '.undivided';
    private const PARTITION_KEY_STORE_SUFFIX = '.partitionKeys';

    /**
     * @param object[] $extensionObjects
     */
    public static function isConfiguredBy(AnnotationFinder $annotationFinder, array $extensionObjects): bool
    {
        return $annotationFinder->findAnnotatedMethods(PartitionDeadLetter::class) !== []
            || $annotationFinder->findAnnotatedClasses(PartitionDeadLetter::class) !== []
            || ExtensionObjectResolver::resolve(PartitionDeadLetterConfiguration::class, $extensionObjects) !== [];
    }

    /**
     * @param object[] $extensionObjects
     */
    public static function register(
        Configuration $configuration,
        AnnotationFinder $annotationFinder,
        InterfaceToCallRegistry $interfaceToCallRegistry,
        array $extensionObjects,
        DbalConfiguration $dbalConfiguration,
    ): void {
        $isConfigured = self::isConfiguredBy($annotationFinder, $extensionObjects);
        $connectionReference = $dbalConfiguration->getDeadLetterConnectionReference();
        if (! $isConfigured) {
            $configuration->registerServiceDefinition(DeadLetterReplayOrder::class, new Definition(AnyDeadLetterReplayOrder::class));

            return;
        }

        if (! $configuration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create(
                'Holding the messages of a failed partition key in the dead letter (the #[PartitionDeadLetter] attribute and PartitionDeadLetterConfiguration) requires an Ecotone Enterprise licence. '
                . 'Provide your licence key as `licenceKey` in the Symfony, Laravel or Tempest Ecotone configuration, or as the `licenceKey:` argument of EcotoneLite, or start a trial at https://ecotone.tech/pricing. '
                . 'Alternatively, remove #[PartitionDeadLetter] and PartitionDeadLetterConfiguration.'
            );
        }
        if (! $dbalConfiguration->deadLetterEnabled) {
            throw ConfigurationException::create(
                'The #[PartitionDeadLetter] attribute or PartitionDeadLetterConfiguration is used, yet the Dbal dead letter is switched off, so there is nowhere to hold the messages of a failed partition key. '
                . 'Switch it on with DbalConfiguration::createWithDefaults()->withDeadLetter(true), or remove the attribute and the configuration.'
            );
        }

        $configuration->registerServiceDefinition(
            HeldPartitions::class,
            $dbalConfiguration->deadLetterInMemory
                ? new Definition(InMemoryHeldPartitions::class)
                : new Definition(DbalHeldPartitions::class, [
                    CachedConnectionFactory::registerIn(configuration: $configuration, connectionKey: $connectionReference, reconnectableConnectionFactory: DbalReconnectableConnectionFactory::definitionFor($connectionReference)),
                    Reference::to(DeadLetterTableManager::class),
                ])
        );
        $configuration->registerServiceDefinition(
            PartitionedHandlers::class,
            new Definition(PartitionedHandlers::class, [
                Reference::to(AsyncEndpointAnnotationContext::class),
                self::partitionedHandlers(configuration: $configuration, annotationFinder: $annotationFinder, interfaceToCallRegistry: $interfaceToCallRegistry, extensionObjects: $extensionObjects),
            ])
        );
        $configuration->registerServiceDefinition(
            DeadLetterReplayOrder::class,
            new Definition(PartitionDeadLetterReplayOrder::class, [Reference::to(HeldPartitions::class), Reference::to(MessageBuilder::class)])
        );
        $configuration->registerServiceDefinition(
            PartitionReplay::class,
            new Definition(PartitionReplay::class, [
                Reference::to(HeldPartitions::class),
                Reference::to(DbalDeadLetterBuilder::storeReferenceFor(DeadLetterGateway::class)),
                Reference::to(DeadLetterHandler::class . '.' . DeadLetterGateway::class),
                Reference::to(MessagingEntrypointService::class),
            ])
        );
        $configuration->registerServiceDefinition(
            PartitionDeadLetterInterceptor::class,
            new Definition(PartitionDeadLetterInterceptor::class, [
                Reference::to(PartitionedHandlers::class),
                Reference::to(HeldPartitions::class),
                Reference::to(PartitionReplay::class),
                Reference::to(ErrorChannelService::class),
                Reference::to(ChannelResolver::class),
                Reference::to(MessageHeadersPropagatorInterceptor::class),
                DbalDeadLetterBuilder::STORE_CHANNEL,
            ])
        );
        $configuration->registerAroundMethodInterceptor(
            AroundInterceptorBuilder::create(
                referenceName: PartitionDeadLetterInterceptor::class,
                interfaceToCall: $interfaceToCallRegistry->getFor(PartitionDeadLetterInterceptor::class, 'handle'),
                precedence: Precedence::ERROR_CHANNEL_PRECEDENCE + 1,
                pointcut: AsynchronousRunningEndpoint::class,
            )
        );
        self::registerGateway($configuration, $interfaceToCallRegistry);
        $configuration->registerServiceDefinition(PulseHeldPartitions::class, new Definition(PulseHeldPartitions::class, [Reference::to(HeldPartitions::class)]));
        self::registerConsoleCommands($configuration, $interfaceToCallRegistry);
    }

    private static function registerGateway(Configuration $configuration, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $partition = [
            HeaderBuilder::create('trackingName', PartitionDeadLetterActions::TRACKING_NAME_HEADER),
            HeaderBuilder::create('partitionKey', PartitionDeadLetterActions::PARTITION_KEY_HEADER),
        ];
        $partitionOfGateway = [
            GatewayHeaderBuilder::create('trackingName', PartitionDeadLetterActions::TRACKING_NAME_HEADER),
            GatewayHeaderBuilder::create('partitionKey', PartitionDeadLetterActions::PARTITION_KEY_HEADER),
        ];
        $configuration->registerServiceDefinition(
            PartitionDeadLetterActions::class,
            new Definition(PartitionDeadLetterActions::class, [
                Reference::to(HeldPartitions::class),
                Reference::to(PartitionReplay::class),
                Reference::to(DeadLetterHandler::class . '.' . DeadLetterGateway::class),
            ])
        );
        $configuration
            ->registerMessageHandler(
                MessageProcessorActivatorBuilder::createForMethod(PartitionDeadLetterActions::class, $interfaceToCallRegistry->getFor(PartitionDeadLetterActions::class, 'list'))
                    ->withInputChannelName(PartitionDeadLetterActions::LIST_CHANNEL)
            )
            ->registerMessageHandler(
                MessageProcessorActivatorBuilder::createForMethod(PartitionDeadLetterActions::class, $interfaceToCallRegistry->getFor(PartitionDeadLetterActions::class, 'replay'))
                    ->withInputChannelName(PartitionDeadLetterActions::REPLAY_CHANNEL)
                    ->withMethodParameterConverters($partition)
            )
            ->registerMessageHandler(
                MessageProcessorActivatorBuilder::createForMethod(PartitionDeadLetterActions::class, $interfaceToCallRegistry->getFor(PartitionDeadLetterActions::class, 'delete'))
                    ->withInputChannelName(PartitionDeadLetterActions::DELETE_CHANNEL)
                    ->withMethodParameterConverters($partition)
            )
            ->registerGatewayBuilder(GatewayProxyBuilder::create(referenceName: PartitionDeadLetterGateway::class, interfaceName: PartitionDeadLetterGateway::class, methodName: 'list', requestChannelName: PartitionDeadLetterActions::LIST_CHANNEL))
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(referenceName: PartitionDeadLetterGateway::class, interfaceName: PartitionDeadLetterGateway::class, methodName: 'replay', requestChannelName: PartitionDeadLetterActions::REPLAY_CHANNEL)
                    ->withParameterConverters($partitionOfGateway)
            )
            ->registerGatewayBuilder(
                GatewayProxyBuilder::create(referenceName: PartitionDeadLetterGateway::class, interfaceName: PartitionDeadLetterGateway::class, methodName: 'delete', requestChannelName: PartitionDeadLetterActions::DELETE_CHANNEL)
                    ->withParameterConverters($partitionOfGateway)
            );
    }

    private static function registerConsoleCommands(Configuration $configuration, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $configuration->registerServiceDefinition(PartitionDeadLetterConsoleCommand::class, new Definition(PartitionDeadLetterConsoleCommand::class));
        foreach ([
            'listPartitions' => [PartitionDeadLetterConsoleCommand::LIST_COMMAND_NAME, 'Lists the partition keys held in the dead letter'],
            'replayPartition' => [PartitionDeadLetterConsoleCommand::REPLAY_COMMAND_NAME, 'Replays the messages of a held partition key in the order they failed'],
            'deletePartition' => [PartitionDeadLetterConsoleCommand::DELETE_COMMAND_NAME, 'Deletes the messages of a held partition key, letting new ones through'],
        ] as $methodName => [$commandName, $description]) {
            [$messageHandlerBuilder, $consoleCommand] = ConsoleCommandModule::prepareConsoleCommandForReference(
                reference: new Reference(PartitionDeadLetterConsoleCommand::class),
                interfaceToCallReference: new InterfaceToCallReference(PartitionDeadLetterConsoleCommand::class, $methodName),
                commandName: $commandName,
                interfaceToCallRegistry: $interfaceToCallRegistry,
                description: $description,
            );
            $configuration->registerMessageHandler($messageHandlerBuilder)->registerConsoleCommand($consoleCommand);
        }
    }

    /**
     * @param object[] $extensionObjects
     * @return PulseSnapshotSectionRegistration[]
     */
    public static function pulseSections(AnnotationFinder $annotationFinder, array $extensionObjects, DbalConfiguration $dbalConfiguration): array
    {
        if (! $dbalConfiguration->deadLetterEnabled || ExtensionObjectResolver::resolve(PulseConfiguration::class, $extensionObjects) === [] || ! self::isConfiguredBy($annotationFinder, $extensionObjects)) {
            return [];
        }

        return [new PulseSnapshotSectionRegistration(sectionName: PulseHeldPartitions::SECTION_NAME, serviceReference: PulseHeldPartitions::class, tenantScoped: true)];
    }

    public static function dividing(Definition $deadLetterStore, string $storeReference, string $connectionFactoryReference, bool $isInMemory, Configuration $configuration): Definition
    {
        $configuration->registerServiceDefinition($storeReference . self::UNDIVIDED_STORE_SUFFIX, $deadLetterStore);
        $configuration->registerServiceDefinition(
            $storeReference . self::PARTITION_KEY_STORE_SUFFIX,
            $isInMemory
                ? new Definition(InMemoryPartitionKeyDeadLetterStore::class, [
                    Reference::to($storeReference . self::UNDIVIDED_STORE_SUFFIX),
                    Reference::to(HeldPartitions::class),
                ])
                : new Definition(DbalPartitionKeyDeadLetterStore::class, [
                    Reference::to($storeReference . self::UNDIVIDED_STORE_SUFFIX),
                    CachedConnectionFactory::registerIn(configuration: $configuration, connectionKey: $connectionFactoryReference, reconnectableConnectionFactory: DbalReconnectableConnectionFactory::definitionFor($connectionFactoryReference)),
                    Reference::to(DeadLetterTableManager::class),
                ])
        );

        return new Definition(PartitionDeadLetterStore::class, [
            Reference::to($storeReference . self::PARTITION_KEY_STORE_SUFFIX),
            Reference::to(PartitionedHandlers::class),
            Reference::to(MessageBuilder::class),
        ]);
    }

    /**
     * @param object[] $extensionObjects
     * @return array<string, Definition>
     */
    private static function partitionedHandlers(Configuration $configuration, AnnotationFinder $annotationFinder, InterfaceToCallRegistry $interfaceToCallRegistry, array $extensionObjects): array
    {
        $messageChannels = self::configuredMessageChannels($extensionObjects);
        $aggregates = self::configuredAggregates($extensionObjects);
        $handlers = self::handlers($annotationFinder);

        self::assertEveryAttributeIsOnAnAsynchronousHandler($annotationFinder, $handlers);
        self::assertEveryConfiguredMessageChannelIsConsumed($messageChannels, $handlers);
        self::assertEveryConfiguredAggregateIsHandledAsynchronously($aggregates, $handlers);

        $partitionedHandlers = [];
        foreach ($handlers as $handler) {
            $partitionedHandler = self::partitionedHandlerOf(handler: $handler, messageChannels: $messageChannels, aggregates: $aggregates, interfaceToCallRegistry: $interfaceToCallRegistry);
            if ($partitionedHandler === null) {
                continue;
            }

            /** @var EndpointAnnotation $endpointAnnotation */
            $endpointAnnotation = $handler->getAnnotationForMethod();
            self::assertFailuresReachTheDbalDeadLetter(handler: $handler, asynchronousChannel: self::asynchronousChannelsOf($handler)[0], extensionObjects: $extensionObjects);
            $configuration->registerAsynchronousExecutionAttribute($endpointAnnotation->getEndpointId(), new PartitionedEndpoint($endpointAnnotation->getEndpointId()));
            $partitionedHandlers[$endpointAnnotation->getEndpointId()] = $partitionedHandler;
        }

        return $partitionedHandlers;
    }

    /**
     * @param object[] $extensionObjects
     * @return list<string>
     */
    private static function configuredMessageChannels(array $extensionObjects): array
    {
        return array_values(array_unique(array_merge([], ...array_map(
            static fn (PartitionDeadLetterConfiguration $partitionConfiguration): array => $partitionConfiguration->messageChannels,
            ExtensionObjectResolver::resolve(PartitionDeadLetterConfiguration::class, $extensionObjects),
        ))));
    }

    /**
     * @param object[] $extensionObjects
     * @return list<class-string>
     */
    private static function configuredAggregates(array $extensionObjects): array
    {
        return array_values(array_unique(array_merge([], ...array_map(
            static fn (PartitionDeadLetterConfiguration $partitionConfiguration): array => $partitionConfiguration->aggregates,
            ExtensionObjectResolver::resolve(PartitionDeadLetterConfiguration::class, $extensionObjects),
        ))));
    }

    /**
     * @param AnnotatedFinding[] $handlers
     */
    private static function assertEveryAttributeIsOnAnAsynchronousHandler(AnnotationFinder $annotationFinder, array $handlers): void
    {
        foreach ($handlers as $handler) {
            if (self::firstOf($handler->getMethodAnnotations(), PartitionDeadLetter::class) !== null && self::asynchronousChannelsOf($handler) === []) {
                throw ConfigurationException::create(
                    "#[PartitionDeadLetter] is placed on {$handler->getClassName()}::{$handler->getMethodName()}, which is handled synchronously, yet a partition key is held in the dead letter only for a handler consuming a message channel. "
                    . "Make the handler asynchronous with #[Asynchronous('channel_name')], or remove #[PartitionDeadLetter] from it."
                );
            }
        }

        foreach ($annotationFinder->findAnnotatedClasses(PartitionDeadLetter::class) as $className) {
            $asynchronousHandlersOfClass = array_filter($handlers, static fn (AnnotatedFinding $handler): bool => $handler->getClassName() === $className && self::asynchronousChannelsOf($handler) !== []);
            if ($asynchronousHandlersOfClass === []) {
                throw ConfigurationException::create(
                    "#[PartitionDeadLetter] is placed on class {$className}, which has no asynchronous handler, yet a partition key is held in the dead letter only for a handler consuming a message channel, so no partition key would ever be held. "
                    . "Make its handlers asynchronous with #[Asynchronous('channel_name')], or remove #[PartitionDeadLetter] from the class."
                );
            }
        }
    }

    /**
     * @param list<string> $messageChannels
     * @param AnnotatedFinding[] $handlers
     */
    private static function assertEveryConfiguredMessageChannelIsConsumed(array $messageChannels, array $handlers): void
    {
        $consumedMessageChannels = array_merge([], ...array_map(self::asynchronousChannelsOf(...), $handlers));
        foreach (array_diff($messageChannels, $consumedMessageChannels) as $unusedMessageChannel) {
            throw ConfigurationException::create(
                "PartitionDeadLetterConfiguration::withMessageChannels() names message channel `{$unusedMessageChannel}`, yet no handler is #[Asynchronous] on it, so no partition key would ever be held for it. "
                . "Name a message channel a handler consumes with #[Asynchronous('{$unusedMessageChannel}')], or remove it from the configuration."
            );
        }
    }

    /**
     * @param list<class-string> $aggregates
     * @param AnnotatedFinding[] $handlers
     */
    private static function assertEveryConfiguredAggregateIsHandledAsynchronously(array $aggregates, array $handlers): void
    {
        $asynchronouslyHandledClasses = array_map(
            static fn (AnnotatedFinding $handler): string => $handler->getClassName(),
            array_filter($handlers, static fn (AnnotatedFinding $handler): bool => self::asynchronousChannelsOf($handler) !== []),
        );
        foreach (array_diff($aggregates, $asynchronouslyHandledClasses) as $unusedAggregate) {
            throw ConfigurationException::create(
                "PartitionDeadLetterConfiguration::withAggregates() names `{$unusedAggregate}`, yet it has no #[Asynchronous] handler, so no partition key would ever be held for it. "
                . 'Name a class with an #[Asynchronous] command or event handler, or remove it from the configuration.'
            );
        }
    }

    /**
     * @param list<string> $messageChannels
     * @param list<class-string> $aggregates
     */
    private static function partitionedHandlerOf(AnnotatedFinding $handler, array $messageChannels, array $aggregates, InterfaceToCallRegistry $interfaceToCallRegistry): ?Definition
    {
        $asynchronousChannels = self::asynchronousChannelsOf($handler);
        if ($asynchronousChannels === []) {
            return null;
        }

        /** @var PartitionDeadLetter|null $methodAttribute */
        $methodAttribute = self::firstOf($handler->getMethodAnnotations(), PartitionDeadLetter::class);
        /** @var PartitionDeadLetter|null $classAttribute */
        $classAttribute = self::firstOf($handler->getClassAnnotations(), PartitionDeadLetter::class);
        $attribute = $methodAttribute ?? $classAttribute;
        if ($handler->hasClassAnnotation(Projection::class)) {
            if ($attribute !== null) {
                throw ConfigurationException::create(
                    "#[PartitionDeadLetter] is placed on projection {$handler->getClassName()}, yet a projection is not held by partition key: it reads its stream from its own position and stops there when an event fails. "
                    . 'Remove #[PartitionDeadLetter] from the projection; to keep a failing aggregate from stopping the others, partition the projection itself with #[Partitioned].'
                );
            }

            return null;
        }
        $isOfConfiguredAggregate = in_array($handler->getClassName(), $aggregates, true);
        if ($attribute === null && ! $isOfConfiguredAggregate && array_intersect($asynchronousChannels, $messageChannels) === []) {
            return null;
        }

        $partitionKey = self::partitionKeyOf(handler: $handler, attribute: $attribute, isOnMethod: $methodAttribute !== null, interfaceToCallRegistry: $interfaceToCallRegistry);
        if ($partitionKey === null) {
            return null;
        }

        /** @var EndpointAnnotation $endpointAnnotation */
        $endpointAnnotation = $handler->getAnnotationForMethod();

        return new Definition(PartitionedHandler::class, [
            $attribute?->trackingName ?? ($methodAttribute === null && ($classAttribute !== null || $isOfConfiguredAggregate) ? $handler->getClassName() : $endpointAnnotation->getEndpointId()),
            $partitionKey,
        ]);
    }

    private static function partitionKeyOf(AnnotatedFinding $handler, ?PartitionDeadLetter $attribute, bool $isOnMethod, InterfaceToCallRegistry $interfaceToCallRegistry): ?Definition
    {
        $placement = $isOnMethod ? "{$handler->getClassName()}::{$handler->getMethodName()}" : "class {$handler->getClassName()}";
        $expression = $attribute?->getExpression();
        $hasExpression = $expression !== null && $expression !== '';
        $partitionHeaderName = $attribute === null ? '' : $attribute->partitionHeaderName;

        if ($hasExpression && $partitionHeaderName !== '') {
            throw ConfigurationException::create(
                "#[PartitionDeadLetter] on {$placement} takes the partition key from either partitionHeaderName or expression, yet both were given. "
                . 'Keep the one the partition key should come from and remove the other.'
            );
        }
        if ($hasExpression) {
            $handledMethod = $interfaceToCallRegistry->getFor($handler->getClassName(), $handler->getMethodName());

            return new Definition(ExpressionPartitionKey::class, [
                AttributeExpressionExecutorCompiler::compile(
                    attributeWithExpression: $attribute,
                    attributeDeclaration: new AttributeDeclaration(attributeClassName: PartitionDeadLetter::class, className: $handler->getClassName(), methodName: $isOnMethod ? $handler->getMethodName() : null),
                    owner: $handledMethod,
                    targetParameterName: 'expression',
                ),
                self::handledPayloadOf($handledMethod),
                Reference::to(MessageBuilder::class),
            ]);
        }
        if ($partitionHeaderName !== '') {
            return new Definition(HeaderPartitionKey::class, [$partitionHeaderName]);
        }
        if ($handler->hasClassAnnotation(Aggregate::class)) {
            return new Definition(HandlingAggregatePartitionKey::class, [$handler->getClassName()]);
        }
        if ($handler->getAnnotationForMethod() instanceof EventHandler) {
            return new Definition(PublishingAggregatePartitionKey::class);
        }
        if ($attribute === null) {
            return null;
        }

        throw ConfigurationException::create(
            "#[PartitionDeadLetter] on {$placement} names no partition key for {$handler->getClassName()}::{$handler->getMethodName()}: it has neither partitionHeaderName nor expression, and the handler is neither a handler of an aggregate (held by the aggregate's identifier) nor an event handler (held by the aggregate that published the event), so no partition key would ever be held. "
            . "Name the header that carries the partition key with #[PartitionDeadLetter(partitionHeaderName: 'orderId')], calculate it with #[PartitionDeadLetter(expression: 'payload.orderId')], or remove #[PartitionDeadLetter]."
        );
    }

    private static function handledPayloadOf(InterfaceToCall $handledMethod): Definition
    {
        foreach (ParameterConverterAnnotationFactory::createParameterConverters($handledMethod) as $parameterConverter) {
            if ($parameterConverter instanceof PayloadBuilder) {
                return $parameterConverter->compile($handledMethod);
            }
        }

        $takesThePayloadFirst = $handledMethod->hasFirstParameter()
            && ! $handledMethod->hasMethodAnnotation(Type::object(IgnorePayload::class))
            && ! $handledMethod->getFirstParameter()->isAnnotation()
            && ! $handledMethod->getFirstParameter()->isMessage()
            && ParameterConverterAnnotationFactory::getConverterFor($handledMethod->getFirstParameter(), $handledMethod) === null;
        if ($takesThePayloadFirst) {
            return PayloadBuilder::create($handledMethod->getFirstParameterName())->compile($handledMethod);
        }

        return new Definition(PayloadConverter::class, [
            Reference::to(ConversionService::REFERENCE_NAME),
            Reference::to(EventMapper::class),
            $handledMethod->toString(),
            'payload',
            Type::anything(),
        ]);
    }

    /**
     * @param object[] $extensionObjects
     */
    private static function assertFailuresReachTheDbalDeadLetter(AnnotatedFinding $handler, string $asynchronousChannel, array $extensionObjects): void
    {
        $deadLetterChannel = self::deadLetterChannelOf(handler: $handler, asynchronousChannel: $asynchronousChannel, extensionObjects: $extensionObjects);
        if ($deadLetterChannel === DbalDeadLetterBuilder::STORE_CHANNEL) {
            return;
        }

        $storeChannel = DbalDeadLetterBuilder::STORE_CHANNEL;
        throw ConfigurationException::create(
            "{$handler->getClassName()}::{$handler->getMethodName()} holds failed partition keys in the dead letter, yet its failed messages " . ($deadLetterChannel === null ? 'are sent to no error channel' : "end in `{$deadLetterChannel}`")
            . ", not in the Dbal dead letter `{$storeChannel}`, so a failed partition key would never be held. "
            . "Send them there with ServiceConfiguration::withDefaultErrorChannel('{$storeChannel}'), with ErrorHandlerConfiguration::createWithDeadLetterChannel('errorChannel', \$retries, '{$storeChannel}') as the default error channel's handler, "
            . "or on the handler with #[Asynchronous('{$asynchronousChannel}', asynchronousExecution: [new ErrorChannel('{$storeChannel}')])]; or remove #[PartitionDeadLetter] and PartitionDeadLetterConfiguration for this handler."
        );
    }

    /**
     * @param object[] $extensionObjects
     */
    private static function deadLetterChannelOf(AnnotatedFinding $handler, string $asynchronousChannel, array $extensionObjects): ?string
    {
        /** @var Asynchronous $asynchronous */
        $asynchronous = self::firstOf($handler->getMethodAnnotations(), Asynchronous::class) ?? self::firstOf($handler->getClassAnnotations(), Asynchronous::class);
        $delayedRetry = self::firstOf($asynchronous->asynchronousExecution, DelayedRetry::class);
        if ($delayedRetry !== null) {
            return $delayedRetry->deadLetterChannel;
        }

        $errorChannel = self::firstOf($asynchronous->asynchronousExecution, ErrorChannel::class)?->errorChannelName;
        /** @var PollingMetadata $pollingMetadata */
        foreach (ExtensionObjectResolver::resolve(PollingMetadata::class, $extensionObjects) as $pollingMetadata) {
            if ($errorChannel === null && $pollingMetadata->getEndpointId() === $asynchronousChannel && $pollingMetadata->getErrorChannelName() !== '') {
                $errorChannel = $pollingMetadata->getErrorChannelName();
            }
        }
        $errorChannel ??= ExtensionObjectResolver::resolveUnique(ServiceConfiguration::class, $extensionObjects, ServiceConfiguration::createWithDefaults())->defaultErrorChannel;

        return $errorChannel === null ? null : (DeadLetterChannels::configuredBy($extensionObjects)[$errorChannel] ?? $errorChannel);
    }

    /**
     * @return AnnotatedFinding[]
     */
    private static function handlers(AnnotationFinder $annotationFinder): array
    {
        $handlers = [];
        foreach ([...$annotationFinder->findAnnotatedMethods(EndpointAnnotation::class), ...$annotationFinder->findAnnotatedMethods(EventHandler::class)] as $handler) {
            $handlers[$handler->getClassName() . '::' . $handler->getMethodName()] = $handler;
        }

        return array_values($handlers);
    }

    /**
     * @return list<string>
     */
    private static function asynchronousChannelsOf(AnnotatedFinding $handler): array
    {
        /** @var Asynchronous|null $asynchronous */
        $asynchronous = self::firstOf($handler->getMethodAnnotations(), Asynchronous::class) ?? self::firstOf($handler->getClassAnnotations(), Asynchronous::class);

        return $asynchronous === null ? [] : array_values($asynchronous->getChannelName());
    }

    /**
     * @template T of object
     * @param object[] $annotations
     * @param class-string<T> $type
     * @return T|null
     */
    private static function firstOf(array $annotations, string $type): ?object
    {
        foreach ($annotations as $annotation) {
            if ($annotation instanceof $type) {
                return $annotation;
            }
        }

        return null;
    }
}
