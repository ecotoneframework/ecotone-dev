<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Recoverability\Partition;

use Closure;
use Ecotone\AnnotationFinder\AnnotatedFinding;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\DelayedRetry;
use Ecotone\Api\Attribute\ErrorChannel;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\PartitionDeadLetter;
use Ecotone\Api\Attribute\Polling;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Projecting\FromTaggedStream;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\Streaming;
use Ecotone\Dbal\Database\DeadLetterTableManager;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Dbal\Recoverability\AnyDeadLetterReplayOrder;
use Ecotone\Dbal\Recoverability\DeadLetterHandler;
use Ecotone\Dbal\Recoverability\DeadLetterReplayOrder;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\EventSourcing\Mapping\EventMapper;
use Ecotone\Messaging\Attribute\EndpointAnnotation;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ParameterConverterAnnotationFactory;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\AttributeDeclaration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\PollingConsumer\AsyncEndpointAnnotationContext;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionExecutorCompiler;
use Ecotone\Messaging\Handler\Gateway\ErrorChannelService;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter\PayloadConverter;
use Ecotone\Messaging\Handler\Recoverability\DeadLetterChannels;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;
use Ecotone\Projecting\Config\ProjectingModule;

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

    public static function isConfiguredBy(AnnotationFinder $annotationFinder): bool
    {
        return $annotationFinder->findAnnotatedMethods(PartitionDeadLetter::class) !== []
            || $annotationFinder->findAnnotatedClasses(PartitionDeadLetter::class) !== [];
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
        $isConfigured = self::isConfiguredBy($annotationFinder);
        $connectionReference = $dbalConfiguration->getDeadLetterConnectionReference();
        if (! $isConfigured) {
            $configuration->registerServiceDefinition(DeadLetterReplayOrder::class, new Definition(AnyDeadLetterReplayOrder::class));

            return;
        }

        if (! $configuration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create(
                'Holding the messages of a failed partition key in the dead letter (the #[PartitionDeadLetter] attribute) requires an Ecotone Enterprise licence. '
                . 'Provide your licence key as `licenceKey` in the Symfony, Laravel or Tempest Ecotone configuration, or as the `licenceKey:` argument of EcotoneLite, or start a trial at https://ecotone.tech/pricing. '
                . 'Alternatively, remove #[PartitionDeadLetter].'
            );
        }
        if (! $dbalConfiguration->deadLetterEnabled) {
            throw ConfigurationException::create(
                'The #[PartitionDeadLetter] attribute is used, yet the Dbal dead letter is switched off, so there is nowhere to hold the messages of a failed partition key. '
                . 'Switch it on with DbalConfiguration::createWithDefaults()->withDeadLetter(true), or remove the attribute.'
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
            new Definition(PartitionDeadLetterReplayOrder::class, [Reference::to(HeldPartitions::class), Reference::to(MessageBuilder::class), Reference::to(LoggingGateway::class)])
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
                Reference::to(LoggingGateway::class),
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
            Reference::to(HeldPartitions::class),
            Reference::to(MessageBuilder::class),
        ]);
    }

    /**
     * @param object[] $extensionObjects
     * @return array<string, Definition>
     */
    private static function partitionedHandlers(Configuration $configuration, AnnotationFinder $annotationFinder, InterfaceToCallRegistry $interfaceToCallRegistry, array $extensionObjects): array
    {
        $handlers = self::handlers($annotationFinder);
        $partitionedHandlers = self::partitionedProjections(configuration: $configuration, annotationFinder: $annotationFinder, extensionObjects: $extensionObjects);

        self::assertEveryAttributeIsOnAnAsynchronousHandler($annotationFinder, $handlers);

        $firstHandlerUnderDefaultTrackingName = [];
        foreach ($handlers as $handler) {
            $partitionedHandler = self::partitionedHandlerOf(handler: $handler, interfaceToCallRegistry: $interfaceToCallRegistry);
            if ($partitionedHandler === null) {
                continue;
            }
            if (self::attributeOf($handler)?->trackingName === null) {
                $firstHandlerUnderDefaultTrackingName[$handler->getClassName()] ??= $handler;
                self::assertThePartitionKeyComesFromOneSource($firstHandlerUnderDefaultTrackingName[$handler->getClassName()], $handler);
            }

            /** @var EndpointAnnotation $endpointAnnotation */
            $endpointAnnotation = $handler->getAnnotationForMethod();
            $asynchronousChannel = self::asynchronousChannelsOf($handler)[0];
            self::assertFailuresReachTheDbalDeadLetter(
                handlerName: "{$handler->getClassName()}::{$handler->getMethodName()}",
                asynchronous: self::asynchronousOf($handler),
                asynchronousChannel: $asynchronousChannel,
                extensionObjects: $extensionObjects,
                waysOutOnTheHandler: "or on the handler with #[Asynchronous('{$asynchronousChannel}', asynchronousExecution: [new ErrorChannel('" . DbalDeadLetterBuilder::STORE_CHANNEL . "')])]; or remove #[PartitionDeadLetter] from this handler and its class.",
            );
            $configuration->registerAsynchronousExecutionAttribute($endpointAnnotation->getEndpointId(), new PartitionedEndpoint($endpointAnnotation->getEndpointId()));
            $partitionedHandlers[$endpointAnnotation->getEndpointId()] = $partitionedHandler;
        }

        return $partitionedHandlers;
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

    private static function partitionedHandlerOf(AnnotatedFinding $handler, InterfaceToCallRegistry $interfaceToCallRegistry): ?Definition
    {
        if (self::asynchronousChannelsOf($handler) === []) {
            return null;
        }

        $attribute = self::attributeOf($handler);
        if ($attribute === null) {
            return null;
        }
        if ($handler->hasClassAnnotation(Projection::class)) {
            if (self::firstOf($handler->getMethodAnnotations(), PartitionDeadLetter::class) === null) {
                return null;
            }

            /** @var Projection $projection */
            $projection = self::firstOf($handler->getClassAnnotations(), Projection::class);
            throw ConfigurationException::create(
                "#[PartitionDeadLetter] is placed on {$handler->getClassName()}::{$handler->getMethodName()}, an event handler of projection `{$projection->name}`, yet a projection is held as a whole: one trigger runs every event handler of it from the position of its partition. "
                . 'Place #[PartitionDeadLetter] on the projection class, or remove it from the method.'
            );
        }

        return new Definition(PartitionedHandler::class, [
            $attribute->trackingName ?? $handler->getClassName(),
            self::partitionKeyOf(handler: $handler, attribute: $attribute, interfaceToCallRegistry: $interfaceToCallRegistry),
        ]);
    }

    /**
     * @param object[] $extensionObjects
     * @return array<string, Definition>
     */
    private static function partitionedProjections(Configuration $configuration, AnnotationFinder $annotationFinder, array $extensionObjects): array
    {
        $partitionedProjections = [];
        foreach ($annotationFinder->findAnnotatedClasses(Projection::class) as $projectionClassName) {
            /** @var PartitionDeadLetter|null $attribute */
            $attribute = $annotationFinder->findAttributeForClass($projectionClassName, PartitionDeadLetter::class);
            if ($attribute === null) {
                continue;
            }

            /** @var Projection $projection */
            $projection = $annotationFinder->getAttributeForClass($projectionClassName, Projection::class);
            /** @var Partitioned|null $partitioned */
            $partitioned = $annotationFinder->findAttributeForClass($projectionClassName, Partitioned::class);
            /** @var Asynchronous|null $asynchronous */
            $asynchronous = $annotationFinder->findAttributeForClass($projectionClassName, Asynchronous::class);
            $placement = "projection `{$projection->name}` ({$projectionClassName})";
            self::assertProjectionIsPartitionedAndAsynchronous(placement: $placement, partitioned: $partitioned, asynchronous: $asynchronous, replacedAttribute: self::pollingOrStreamingOf($annotationFinder, $projectionClassName));
            self::assertNoPartitionKeyIsNamedOnProjection(placement: $placement, attribute: $attribute);
            self::assertProjectionDoesNotReadATaggedStream(placement: $placement, taggedStreams: $annotationFinder->getAnnotationsForClass($projectionClassName, FromTaggedStream::class));

            $asynchronousChannel = array_values($asynchronous->getChannelName())[0];
            self::assertFailuresReachTheDbalDeadLetter(
                handlerName: ucfirst($placement),
                asynchronous: $asynchronous,
                asynchronousChannel: $asynchronousChannel,
                extensionObjects: $extensionObjects,
                waysOutOnTheHandler: "or on the projection with #[Asynchronous('{$asynchronousChannel}', asynchronousExecution: [new ErrorChannel('" . DbalDeadLetterBuilder::STORE_CHANNEL . "')])]; or remove #[PartitionDeadLetter] from the projection.",
            );
            $triggerEndpointId = ProjectingModule::endpointIdForProjection($projection->name);
            $configuration->registerAsynchronousExecutionAttribute($triggerEndpointId, new PartitionedEndpoint($triggerEndpointId));
            $partitionedProjections[$triggerEndpointId] = new Definition(PartitionedHandler::class, [
                $attribute->trackingName ?? $projectionClassName,
                $partitioned->partitionHeaderName === null
                    ? new Definition(ProjectedAggregatePartitionKey::class)
                    : new Definition(HeaderPartitionKey::class, [$partitioned->partitionHeaderName]),
            ]);
        }

        return $partitionedProjections;
    }

    private static function pollingOrStreamingOf(AnnotationFinder $annotationFinder, string $projectionClassName): ?string
    {
        return match (true) {
            $annotationFinder->findAttributeForClass($projectionClassName, Polling::class) !== null => '#[Polling]',
            $annotationFinder->findAttributeForClass($projectionClassName, Streaming::class) !== null => '#[Streaming]',
            default => null,
        };
    }

    private static function assertProjectionIsPartitionedAndAsynchronous(string $placement, ?Partitioned $partitioned, ?Asynchronous $asynchronous, ?string $replacedAttribute): void
    {
        if ($partitioned !== null && $asynchronous !== null) {
            return;
        }

        $missing = implode(' and ', [...($partitioned === null ? ['#[Partitioned]'] : []), ...($asynchronous === null ? ['#[Asynchronous]'] : [])]);
        $toAdd = implode(' and ', [...($partitioned === null ? ['#[Partitioned]'] : []), ...($asynchronous === null ? ["#[Asynchronous('channel_name')]"] : [])]);
        throw ConfigurationException::create(
            "#[PartitionDeadLetter] is placed on {$placement}, which has no {$missing}, yet a projection is held by partition key only when it is #[Partitioned] and #[Asynchronous]: the key held is the partition the projection reads, held on the triggers the projection consumes from its message channel. "
            . 'Without #[Partitioned] a projection reads its stream from one position and stops there when an event fails; without #[Asynchronous] it consumes no trigger that could wait in the dead letter. '
            . ($replacedAttribute === null ? "Add {$toAdd} to the projection" : "Replace {$replacedAttribute} with {$toAdd} on the projection") . ', or remove #[PartitionDeadLetter] from it.'
        );
    }

    private static function assertNoPartitionKeyIsNamedOnProjection(string $placement, PartitionDeadLetter $attribute): void
    {
        if ($attribute->partitionHeaderName === '' && ($attribute->getExpression() === null || $attribute->getExpression() === '')) {
            return;
        }

        throw ConfigurationException::create(
            "#[PartitionDeadLetter] on {$placement} names a partition key with partitionHeaderName or expression, yet a projection is held by the partition it reads, so the key cannot come from anywhere else. "
            . "Remove partitionHeaderName and expression from #[PartitionDeadLetter]; to partition the projection by a header, name it with #[Partitioned('header_name')]."
        );
    }

    /**
     * @param FromTaggedStream[] $taggedStreams
     */
    private static function assertProjectionDoesNotReadATaggedStream(string $placement, array $taggedStreams): void
    {
        if ($taggedStreams === []) {
            return;
        }

        throw ConfigurationException::create(
            "#[PartitionDeadLetter] is placed on {$placement}, which reads #[FromTaggedStream('{$taggedStreams[0]->tag}')], yet one event may carry several values of the tag and trigger several partitions at once, while a message waits in the dead letter for one partition key only. "
            . 'Read the events with #[FromAggregateStream] or #[FromStream] to partition the projection by aggregate or by a header, or remove #[PartitionDeadLetter] from it.'
        );
    }

    private static function attributeOf(AnnotatedFinding $handler): ?PartitionDeadLetter
    {
        return self::firstOf($handler->getMethodAnnotations(), PartitionDeadLetter::class) ?? self::firstOf($handler->getClassAnnotations(), PartitionDeadLetter::class);
    }

    private static function assertThePartitionKeyComesFromOneSource(AnnotatedFinding $handler, AnnotatedFinding $otherHandler): void
    {
        $source = self::partitionKeySourceOf($handler);
        $otherSource = self::partitionKeySourceOf($otherHandler);
        if ($source === $otherSource) {
            return;
        }

        throw ConfigurationException::create(
            "{$handler->getClassName()}::{$handler->getMethodName()} takes the partition key from {$source} and {$otherHandler->getClassName()}::{$otherHandler->getMethodName()} takes it from {$otherSource}, yet both hold it under the tracking name `{$handler->getClassName()}`, the default for the handlers of one class, so the same value of two different things would hold the messages of each other. "
            . "Take the partition key from the same source in both handlers, or give each handler its own tracking name with #[PartitionDeadLetter(trackingName: '...')]."
        );
    }

    private static function partitionKeySourceOf(AnnotatedFinding $handler): string
    {
        $methodAttribute = self::firstOf($handler->getMethodAnnotations(), PartitionDeadLetter::class);
        $attribute = self::attributeOf($handler);
        $expression = $attribute?->getExpression();
        if ($expression instanceof Closure) {
            return 'the closure on ' . ($methodAttribute !== null ? "{$handler->getClassName()}::{$handler->getMethodName()}" : "class {$handler->getClassName()}");
        }
        if ($expression !== null && $expression !== '') {
            return "the expression `{$expression}`";
        }
        if ($attribute !== null && $attribute->partitionHeaderName !== '') {
            return "the header `{$attribute->partitionHeaderName}`";
        }

        return $handler->hasClassAnnotation(Aggregate::class) ? 'the identifier of the aggregate' : 'the aggregate that published the event';
    }

    private static function partitionKeyOf(AnnotatedFinding $handler, PartitionDeadLetter $attribute, InterfaceToCallRegistry $interfaceToCallRegistry): Definition
    {
        $isOnMethod = self::firstOf($handler->getMethodAnnotations(), PartitionDeadLetter::class) !== null;
        $placement = $isOnMethod ? "{$handler->getClassName()}::{$handler->getMethodName()}" : "class {$handler->getClassName()}";
        $expression = $attribute->getExpression();
        $hasExpression = $expression !== null && $expression !== '';
        $partitionHeaderName = $attribute->partitionHeaderName;

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

        throw ConfigurationException::create(
            "#[PartitionDeadLetter] on {$placement} names no partition key for {$handler->getClassName()}::{$handler->getMethodName()}: it has neither partitionHeaderName nor expression, and the handler is neither a handler of an aggregate (held by the aggregate's identifier) nor an event handler (held by the aggregate that published the event), so no partition key would ever be held. "
            . "Name the header that carries the partition key with #[PartitionDeadLetter(partitionHeaderName: 'orderId')], calculate it with #[PartitionDeadLetter(expression: 'payload.orderId')], or remove #[PartitionDeadLetter]."
        );
    }

    private static function handledPayloadOf(InterfaceToCall $handledMethod): Definition
    {
        foreach (ParameterConverterAnnotationFactory::createParameterWithDefaults($handledMethod) as $parameterConverter) {
            if ($parameterConverter instanceof PayloadBuilder) {
                return $parameterConverter->compile($handledMethod);
            }
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
    private static function assertFailuresReachTheDbalDeadLetter(string $handlerName, Asynchronous $asynchronous, string $asynchronousChannel, array $extensionObjects, string $waysOutOnTheHandler): void
    {
        $deadLetterChannel = self::deadLetterChannelOf(asynchronous: $asynchronous, asynchronousChannel: $asynchronousChannel, extensionObjects: $extensionObjects);
        if ($deadLetterChannel === DbalDeadLetterBuilder::STORE_CHANNEL) {
            return;
        }

        $storeChannel = DbalDeadLetterBuilder::STORE_CHANNEL;
        throw ConfigurationException::create(
            "{$handlerName} holds failed partition keys in the dead letter, yet its failed messages " . ($deadLetterChannel === null ? 'are sent to no error channel' : "end in `{$deadLetterChannel}`")
            . ", not in the Dbal dead letter `{$storeChannel}`, so a failed partition key would never be held. "
            . "Send them there with ServiceConfiguration::withDefaultErrorChannel('{$storeChannel}'), with ErrorHandlerConfiguration::createWithDeadLetterChannel('errorChannel', \$retries, '{$storeChannel}') as the default error channel's handler, "
            . $waysOutOnTheHandler
        );
    }

    /**
     * @param object[] $extensionObjects
     */
    private static function deadLetterChannelOf(Asynchronous $asynchronous, string $asynchronousChannel, array $extensionObjects): ?string
    {
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

    private static function asynchronousOf(AnnotatedFinding $handler): ?Asynchronous
    {
        return self::firstOf($handler->getMethodAnnotations(), Asynchronous::class) ?? self::firstOf($handler->getClassAnnotations(), Asynchronous::class);
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
