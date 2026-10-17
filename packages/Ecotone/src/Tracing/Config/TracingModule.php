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
use Ecotone\Api\Attribute\ModuleAnnotation;
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
use Ecotone\Messaging\Handler\ServiceActivator\ServiceActivatorBuilder;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;
use Ecotone\Projecting\ProjectionTriggerEndpoint;
use Ecotone\Tracing\CompositeSpanRecorder;
use Ecotone\Tracing\EcotoneTracer;
use Ecotone\Tracing\ExpiredTraceRemover;
use Ecotone\Tracing\InMemoryTraceStore;
use Ecotone\Tracing\StoredTraceCompletion;
use Ecotone\Tracing\StoringSpanRecorder;
use Ecotone\Tracing\Tracer;
use Ecotone\Tracing\TraceRecordingInterceptor;
use Ecotone\Tracing\TraceStore;
use Ecotone\Tracing\TracingInterceptor;

use function implode;

use ReflectionClass;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class TracingModule extends NoExternalConfigurationModule implements AnnotationModule
{
    private const REMOVE_EXPIRED_TRACES_CHANNEL = 'ecotone.tracing.removeExpiredTraces';

    /**
     * @param string[] $traceCompletedSubscribers
     * @param string[] $spanEndedSubscribers
     */
    private function __construct(
        private array $traceCompletedSubscribers,
        private array $spanEndedSubscribers,
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self(
            self::placementsOf($annotationRegistrationService, OnTraceCompleted::class),
            self::placementsOf($annotationRegistrationService, OnSpanEnded::class),
        );
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $tracingConfiguration = ExtensionObjectResolver::resolveUnique(TracingConfiguration::class, $extensionObjects, TracingConfiguration::createWithDefaults());
        $tracerReferences = ExtensionObjectResolver::resolve(TracerReference::class, $extensionObjects);
        if (count($tracerReferences) > 1) {
            throw ConfigurationException::create('Only one Tracer can be active, yet these were registered: ' . implode(', ', array_map(fn (TracerReference $reference): string => $reference->referenceName, $tracerReferences)) . '. Enable a single tracing package.');
        }
        $this->refuseMisconfiguredStoredTraces($messagingConfiguration, $tracingConfiguration);

        if ($tracingConfiguration->spanRecorderReferenceNames === [] && $tracerReferences === [] && ! $tracingConfiguration->storedTraces) {
            return;
        }

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
            new Definition(TracingInterceptor::class, [new Reference(Tracer::class), new Reference(MessageBuilder::class)])
        );

        foreach ([
            'traceCommandHandler' => CommandHandler::class,
            'traceQueryHandler' => QueryHandler::class,
            'traceEventHandler' => EventHandler::class,
            'traceMessageHandler' => InternalHandler::class . '&&not(' . MessageHeadersPropagatorInterceptor::class . ')&&not(' . OnTraceCompleted::class . ')&&not(' . OnSpanEnded::class . ')',
            'traceCommandBus' => CommandBus::class,
            'traceQueryBus' => QueryBus::class,
            'traceEventBus' => EventBus::class,
            'traceAsynchronousEndpoint' => AsynchronousRunningEndpoint::class,
            'traceDistributedBus' => DistributedBus::class,
            'traceProjection' => ProjectionTriggerEndpoint::class,
        ] as $method => $pointcut) {
            $messagingConfiguration->registerAroundMethodInterceptor(
                AroundInterceptorBuilder::create(
                    TracingInterceptor::class,
                    $interfaceToCallRegistry->getFor(TracingInterceptor::class, $method),
                    Precedence::TRACING_PRECEDENCE,
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
        if (($tracingConfiguration->storedTraces || $subscribers !== []) && ! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create('Stored traces and TraceCompletion require an Ecotone Enterprise licence.');
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
                Precedence::DATABASE_TRANSACTION_PRECEDENCE + 1,
                CommandBus::class . '||' . EventBus::class . '||' . QueryBus::class . '||' . AsynchronousRunningEndpoint::class
            )
        );
        $messagingConfiguration->registerMessageChannel(SimpleMessageChannelBuilder::createPublishSubscribeChannel(OnTraceCompleted::CHANNEL));
        $messagingConfiguration->registerMessageChannel(SimpleMessageChannelBuilder::createPublishSubscribeChannel(OnSpanEnded::CHANNEL));

        $messagingConfiguration->registerServiceDefinition(
            StoredTraceCompletion::class,
            new Definition(StoredTraceCompletion::class, [new Reference(TraceStore::class), new Reference(EcotoneClockInterface::class)])
        );
        foreach ([
            'isCompleted' => [GatewayPayloadBuilder::create('traceId')],
            'isCompletedFor' => [GatewayPayloadBuilder::create('traceId'), GatewayHeaderBuilder::create('destination', StoredTraceCompletion::DESTINATION_HEADER)],
            'await' => [GatewayPayloadBuilder::create('traceId'), GatewayHeaderBuilder::create('timeout', StoredTraceCompletion::TIMEOUT_HEADER)],
            'awaitFor' => [GatewayPayloadBuilder::create('traceId'), GatewayHeaderBuilder::create('destination', StoredTraceCompletion::DESTINATION_HEADER), GatewayHeaderBuilder::create('timeout', StoredTraceCompletion::TIMEOUT_HEADER)],
        ] as $method => $parameterConverters) {
            $channel = 'ecotone.tracing.completion.' . $method;
            $messagingConfiguration
                ->registerMessageHandler(ServiceActivatorBuilder::create(StoredTraceCompletion::class, $method)->withInputChannelName($channel))
                ->registerGatewayBuilder(GatewayProxyBuilder::create(TraceCompletion::class, TraceCompletion::class, $method, $channel)->withParameterConverters($parameterConverters));
        }

        $messagingConfiguration->registerServiceDefinition(
            ExpiredTraceRemover::class,
            new Definition(ExpiredTraceRemover::class, [new Reference(TraceStore::class), new Reference(EcotoneClockInterface::class), $tracingConfiguration->storedTracesRetention->inMilliseconds()])
        );
        $messagingConfiguration
            ->registerMessageHandler(ServiceActivatorBuilder::create(ExpiredTraceRemover::class, 'removeExpiredTraces')->withInputChannelName(self::REMOVE_EXPIRED_TRACES_CHANNEL))
            ->registerConsoleCommand(ConsoleCommandConfiguration::create(self::REMOVE_EXPIRED_TRACES_CHANNEL, 'ecotone:tracing:remove-expired-traces', [], 'Removes stored traces older than the configured retention'));
    }

    /**
     * @return string[]
     */
    private static function placementsOf(AnnotationFinder $annotationFinder, string $attributeClassName): array
    {
        $placements = [];
        foreach ($annotationFinder->findAnnotatedMethods($attributeClassName) as $annotatedMethod) {
            $placements[] = '#[' . (new ReflectionClass($attributeClassName))->getShortName() . '] on ' . $annotatedMethod->getClassName() . '::' . $annotatedMethod->getMethodName();
        }

        return $placements;
    }
}
