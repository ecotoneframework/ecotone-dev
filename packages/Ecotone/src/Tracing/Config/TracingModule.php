<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Config;

use function array_map;
use function count;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\InternalHandler;
use Ecotone\Api\Attribute\MessageGateway;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\OrchestratorGateway;
use Ecotone\Api\Attribute\Polling;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\MessageChannelBuilder;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Tracing\CurrentTrace;
use Ecotone\Api\Tracing\OnSpanEnded;
use Ecotone\Api\Tracing\OnTraceCompleted;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Api\Tracing\TraceCompletion;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ConsoleCommandConfiguration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;
use Ecotone\Projecting\ProjectionTriggerEndpoint;
use Ecotone\Tracing\CompositeSpanRecorder;
use Ecotone\Tracing\EcotoneTracer;
use Ecotone\Tracing\ExpiredTraceRemover;
use Ecotone\Tracing\InMemoryTraceStore;
use Ecotone\Tracing\MessageSpanAttributes;
use Ecotone\Tracing\StoredTraceCompletion;
use Ecotone\Tracing\StoringSpanRecorder;
use Ecotone\Tracing\Tracer;
use Ecotone\Tracing\TracerCurrentTrace;
use Ecotone\Tracing\TraceRecordingInterceptor;
use Ecotone\Tracing\TraceStore;
use Ecotone\Tracing\TracingDisabledCurrentTrace;
use Ecotone\Tracing\TracingInterceptor;
use Ecotone\Tracing\UnavailableTraceCompletion;

use function implode;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class TracingModule extends NoExternalConfigurationModule implements AnnotationModule
{
    private const REMOVE_EXPIRED_TRACES_CHANNEL = 'ecotone.tracing.removeExpiredTraces';
    private const USER_MESSAGE_GATEWAYS_POINTCUT = MessageGateway::class . '&&not(' . CommandBus::class . ')&&not(' . EventBus::class . ')&&not(' . QueryBus::class . ')';

    /**
     * @param string[] $traceCompletedSubscribers
     * @param string[] $spanEndedSubscribers
     */
    /**
     * @param string[] $traceCompletedSubscribers
     * @param string[] $spanEndedSubscribers
     * @param array<string, string> $endpointIdsByHandler
     * @param string[] $pollingProjectionNames
     */
    private function __construct(
        private array $traceCompletedSubscribers,
        private array $spanEndedSubscribers,
        private array $endpointIdsByHandler,
        private array $pollingProjectionNames,
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self(
            self::placementsOf($annotationRegistrationService, OnTraceCompleted::class),
            self::placementsOf($annotationRegistrationService, OnSpanEnded::class),
            self::endpointIdsByHandler($annotationRegistrationService),
            self::pollingProjectionNames($annotationRegistrationService),
        );
    }

    /**
     * @return string[]
     */
    private static function pollingProjectionNames(AnnotationFinder $annotationFinder): array
    {
        $names = [];
        foreach ($annotationFinder->findAnnotatedClasses(Projection::class) as $projectionClass) {
            if ($annotationFinder->findAttributeForClass($projectionClass, Polling::class) !== null) {
                $names[] = $annotationFinder->getAttributeForClass($projectionClass, Projection::class)->name;
            }
        }

        return $names;
    }

    private static function registerCompletionGateway(Configuration $messagingConfiguration, string $serviceClass, Definition $service): void
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

    /**
     * @return array<string, string>
     */
    private static function endpointIdsByHandler(AnnotationFinder $annotationFinder): array
    {
        $endpointIds = [];
        foreach ([CommandHandler::class, QueryHandler::class, EventHandler::class, InternalHandler::class] as $attributeClassName) {
            foreach ($annotationFinder->findAnnotatedMethods($attributeClassName) as $annotatedMethod) {
                $endpointIds[$annotatedMethod->getClassName() . '::' . $annotatedMethod->getMethodName()] = $annotatedMethod->getAnnotationForMethod()->getEndpointId();
            }
        }

        return $endpointIds;
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $tracingConfiguration = ExtensionObjectResolver::resolveUnique(TracingConfiguration::class, $extensionObjects, TracingConfiguration::createWithDefaults());
        $tracerReferences = ExtensionObjectResolver::resolve(TracerReference::class, $extensionObjects);
        if (count($tracerReferences) > 1) {
            throw ConfigurationException::create('Only one Tracer can be active, yet these were registered: ' . implode(', ', array_map(fn (TracerReference $reference): string => $reference->referenceName, $tracerReferences)) . '. Enable a single tracing package.');
        }
        $this->refuseMisconfiguredStoredTraces($messagingConfiguration, $tracingConfiguration);

        if (! $tracingConfiguration->storedTraces) {
            self::registerCompletionGateway($messagingConfiguration, UnavailableTraceCompletion::class, new Definition(UnavailableTraceCompletion::class));
        }
        if ($tracingConfiguration->spanRecorderReferenceNames === [] && $tracerReferences === [] && ! $tracingConfiguration->storedTraces) {
            $messagingConfiguration->registerServiceDefinition(CurrentTrace::class, new Definition(TracingDisabledCurrentTrace::class));

            return;
        }
        $messagingConfiguration->registerServiceDefinition(CurrentTrace::class, new Definition(TracerCurrentTrace::class, [new Reference(Tracer::class)]));

        $spanRecorders = array_map(fn (string $referenceName): Reference => new Reference($referenceName), $tracingConfiguration->spanRecorderReferenceNames);
        if ($tracingConfiguration->storedTraces) {
            $this->registerStoredTraces($messagingConfiguration, $extensionObjects, $tracingConfiguration, $interfaceToCallRegistry);
            $spanRecorders[] = new Reference(StoringSpanRecorder::class);
        }

        $messagingConfiguration->registerServiceDefinition(SpanRecorder::class, new Definition(CompositeSpanRecorder::class, [$spanRecorders]));
        $messagingConfiguration->registerServiceDefinition(
            Tracer::class,
            $tracerReferences === []
                ? new Definition(EcotoneTracer::class, [new Reference(EcotoneClockInterface::class), new Reference(SpanRecorder::class)])
                : new Reference($tracerReferences[0]->referenceName)
        );
        $messagingConfiguration->addCompilerPass(new RegisterSpanEventLoggerCompilerPass());
        $messagingConfiguration->addCompilerPass(new TraceEventRoutesCompilerPass());
        $messagingConfiguration->registerServiceDefinition(
            TracingInterceptor::class,
            new Definition(TracingInterceptor::class, [new Reference(Tracer::class), new Reference(MessageBuilder::class), new Reference(MessageSpanAttributes::class), $this->endpointIdsByHandler])
        );
        $messagingConfiguration->registerServiceDefinition(
            MessageSpanAttributes::class,
            new Definition(MessageSpanAttributes::class, [$tracingConfiguration->spanAttributesFromAllHeaders, $tracingConfiguration->spanAttributeHeaders])
        );

        foreach ([
            'traceCommandHandler' => [CommandHandler::class, Precedence::TRACING_HANDLER_PRECEDENCE],
            'traceQueryHandler' => [QueryHandler::class, Precedence::TRACING_HANDLER_PRECEDENCE],
            'traceEventHandler' => [EventHandler::class, Precedence::TRACING_HANDLER_PRECEDENCE],
            'traceMessageHandler' => [InternalHandler::class . '&&not(' . MessageHeadersPropagatorInterceptor::class . ')&&not(' . OnTraceCompleted::class . ')&&not(' . OnSpanEnded::class . ')', Precedence::TRACING_HANDLER_PRECEDENCE],
            'traceProjection' => [ProjectionTriggerEndpoint::class, Precedence::TRACING_HANDLER_PRECEDENCE],
            'traceCommandBus' => [CommandBus::class, Precedence::TRACING_PRECEDENCE],
            'traceQueryBus' => [QueryBus::class, Precedence::TRACING_PRECEDENCE],
            'traceEventBus' => [EventBus::class, Precedence::TRACING_PRECEDENCE],
            'traceAsynchronousEndpoint' => [AsynchronousRunningEndpoint::class, Precedence::TRACING_PRECEDENCE],
            'traceDistributedBus' => [DistributedBus::class, Precedence::TRACING_PRECEDENCE],
            'traceMessageGateway' => [self::USER_MESSAGE_GATEWAYS_POINTCUT, Precedence::TRACING_PRECEDENCE],
            'traceOrchestratorGateway' => [OrchestratorGateway::class, Precedence::TRACING_PRECEDENCE],
        ] as $method => [$pointcut, $precedence]) {
            $messagingConfiguration->registerAroundMethodInterceptor(
                AroundInterceptorBuilder::create(
                    TracingInterceptor::class,
                    $interfaceToCallRegistry->getFor(TracingInterceptor::class, $method),
                    $precedence,
                    $pointcut
                )
            );
        }

        foreach (ExtensionObjectResolver::resolve(MessageChannelBuilder::class, $extensionObjects) as $messageChannelBuilder) {
            if ($messageChannelBuilder->isPollable()) {
                $messagingConfiguration->registerChannelInterceptor(new TracingChannelInterceptorBuilder($messageChannelBuilder->getMessageChannelName()));
            }
        }

        $messagingConfiguration->registerBeforeMethodInterceptor(
            MethodInterceptorBuilder::create(
                Reference::to(TracingInterceptor::class),
                $interfaceToCallRegistry->getFor(TracingInterceptor::class, 'provideContextForDistributedBus'),
                Precedence::DEFAULT_PRECEDENCE,
                DistributedBus::class
            )
        );
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
    }

    private function refuseMisconfiguredStoredTraces(Configuration $messagingConfiguration, TracingConfiguration $tracingConfiguration): void
    {
        $subscribers = [...$this->traceCompletedSubscribers, ...$this->spanEndedSubscribers];
        $enterpriseUsages = [...($tracingConfiguration->storedTraces ? ['TracingConfiguration::withStoredTraces()'] : []), ...$subscribers];
        if ($enterpriseUsages !== [] && ! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create(sprintf(
                '%s %s an Ecotone Enterprise licence. Remove %s to run without one.',
                implode(' and ', $enterpriseUsages),
                count($enterpriseUsages) === 1 ? 'requires' : 'require',
                count($enterpriseUsages) === 1 ? 'it' : 'them',
            ));
        }
        if ($tracingConfiguration->storedTraces || $subscribers === []) {
            return;
        }

        throw ConfigurationException::create(
            implode(', ', $subscribers) . ' needs stored traces, which decide when a trace is complete. Register TracingConfiguration::createWithDefaults()->withStoredTraces() from a #[ServiceContext] method.'
        );
    }

    /**
     * @param object[] $extensionObjects
     */
    private function registerStoredTraces(Configuration $messagingConfiguration, array $extensionObjects, TracingConfiguration $tracingConfiguration, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $traceStoreReferences = ExtensionObjectResolver::resolve(TraceStoreReference::class, $extensionObjects);
        if ($traceStoreReferences === [] && ! $tracingConfiguration->storedTracesInMemory) {
            throw ConfigurationException::create('TracingConfiguration::withStoredTraces() needs a trace store and none is registered. Enable the DBAL package with a connection to store traces in the database, or call withStoredTraces(inMemory: true) to keep them in this process only.');
        }
        $messagingConfiguration->registerServiceDefinition(
            TraceStore::class,
            $traceStoreReferences === [] || $tracingConfiguration->storedTracesInMemory
                ? new Definition(InMemoryTraceStore::class)
                : new Reference($traceStoreReferences[0]->referenceName)
        );
        $messagingConfiguration->registerServiceDefinition(
            StoringSpanRecorder::class,
            new Definition(StoringSpanRecorder::class, [
                new Reference(TraceStore::class),
                new Reference(MessagingEntrypointService::class),
                $this->spanEndedSubscribers !== [],
                $this->traceCompletedSubscribers !== [],
                $tracingConfiguration->storedFailuresOutsideTransaction,
                new Reference(EcotoneClockInterface::class),
            ])
        );
        $messagingConfiguration->registerServiceDefinition(
            TraceRecordingInterceptor::class,
            new Definition(TraceRecordingInterceptor::class, [new Reference(StoringSpanRecorder::class)])
        );
        $messagingConfiguration->registerAroundMethodInterceptor(
            AroundInterceptorBuilder::create(
                TraceRecordingInterceptor::class,
                $interfaceToCallRegistry->getFor(TraceRecordingInterceptor::class, 'recordUnitOfWork'),
                Precedence::TRACE_RECORDING_PRECEDENCE,
                CommandBus::class . '||' . EventBus::class . '||' . QueryBus::class . '||' . AsynchronousRunningEndpoint::class . '||' . OrchestratorGateway::class . '||(' . self::USER_MESSAGE_GATEWAYS_POINTCUT . ')'
            )
        );
        $messagingConfiguration->registerMessageChannel(SimpleMessageChannelBuilder::createPublishSubscribeChannel(OnTraceCompleted::CHANNEL));
        $messagingConfiguration->registerMessageChannel(SimpleMessageChannelBuilder::createPublishSubscribeChannel(OnSpanEnded::CHANNEL));

        self::registerCompletionGateway($messagingConfiguration, StoredTraceCompletion::class, new Definition(StoredTraceCompletion::class, [new Reference(TraceStore::class), new Reference(EcotoneClockInterface::class), $this->pollingProjectionNames]));

        $messagingConfiguration->registerServiceDefinition(
            ExpiredTraceRemover::class,
            new Definition(ExpiredTraceRemover::class, [new Reference(TraceStore::class), new Reference(EcotoneClockInterface::class), $tracingConfiguration->storedTracesRetention->inMilliseconds()])
        );
        $messagingConfiguration
            ->registerMessageHandler(MessageProcessorActivatorBuilder::createForMethod(ExpiredTraceRemover::class, 'removeExpiredTraces')->withInputChannelName(self::REMOVE_EXPIRED_TRACES_CHANNEL))
            ->registerConsoleCommand(ConsoleCommandConfiguration::create(self::REMOVE_EXPIRED_TRACES_CHANNEL, 'ecotone:tracing:remove-expired-traces', [], 'Removes stored traces older than the configured retention'));
    }

    /**
     * @return string[]
     */
    private static function placementsOf(AnnotationFinder $annotationFinder, string $attributeClassName): array
    {
        $placements = [];
        foreach ($annotationFinder->findAnnotatedMethods($attributeClassName) as $annotatedMethod) {
            $placements[] = '#[' . substr((string) strrchr('\\' . $attributeClassName, '\\'), 1) . '] on ' . $annotatedMethod->getClassName() . '::' . $annotatedMethod->getMethodName();
        }

        return $placements;
    }
}
