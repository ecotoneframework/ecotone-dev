<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Config;

use function count;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\MessageGateway;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\OrchestratorGateway;
use Ecotone\Api\Attribute\Polling;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Tracing\OnSpanEnded;
use Ecotone\Api\Tracing\OnTraceCompleted;
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
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Tracing\ExpiredTraceRemover;
use Ecotone\Tracing\InMemoryTraceStore;
use Ecotone\Tracing\StoredTracingGateway;
use Ecotone\Tracing\StoringSpanRecorder;
use Ecotone\Tracing\Tracer;
use Ecotone\Tracing\TraceRecordingInterceptor;
use Ecotone\Tracing\TraceStore;

use function implode;

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
#[ModuleAnnotation]
final class StoredTracesModule extends NoExternalConfigurationModule implements AnnotationModule
{
    private const REMOVE_EXPIRED_TRACES_CHANNEL = 'ecotone.tracing.removeExpiredTraces';
    private const USER_MESSAGE_GATEWAYS_POINTCUT = MessageGateway::class . '&&not(' . CommandBus::class . ')&&not(' . EventBus::class . ')&&not(' . QueryBus::class . ')';

    /**
     * @param string[] $traceCompletedSubscribers
     * @param string[] $spanEndedSubscribers
     * @param string[] $pollingProjectionNames
     */
    private function __construct(
        private array $traceCompletedSubscribers,
        private array $spanEndedSubscribers,
        private array $pollingProjectionNames,
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self(
            self::placementsOf($annotationRegistrationService, OnTraceCompleted::class),
            self::placementsOf($annotationRegistrationService, OnSpanEnded::class),
            self::pollingProjectionNames($annotationRegistrationService),
        );
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $tracingConfiguration = ExtensionObjectResolver::resolveUnique(TracingConfiguration::class, $extensionObjects, TracingConfiguration::createWithDefaults());
        $this->refuseMisconfiguredStoredTraces($messagingConfiguration, $tracingConfiguration);
        if (! $tracingConfiguration->storedTraces) {
            return;
        }
        $this->registerStoredTraces($messagingConfiguration, $extensionObjects, $tracingConfiguration, $interfaceToCallRegistry);
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        $tracingConfiguration = ExtensionObjectResolver::resolveUnique(TracingConfiguration::class, $serviceExtensions, TracingConfiguration::createWithDefaults());

        return $tracingConfiguration->storedTraces ? [new SpanRecorderReference(StoringSpanRecorder::class)] : [];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::CORE_PACKAGE;
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
                $tracingConfiguration->storedTracesOnlyCarryingTraceparent,
                new Reference(EcotoneClockInterface::class),
            ])
        );
        $messagingConfiguration->registerServiceDefinition(
            TraceRecordingInterceptor::class,
            new Definition(TraceRecordingInterceptor::class, [new Reference(StoringSpanRecorder::class), new Reference(Tracer::class)])
        );
        foreach (['publishAfterCommit' => Precedence::TRACE_PUBLICATION_PRECEDENCE, 'recordUnitOfWork' => Precedence::TRACE_RECORDING_PRECEDENCE] as $method => $precedence) {
            $messagingConfiguration->registerAroundMethodInterceptor(
                AroundInterceptorBuilder::create(
                    TraceRecordingInterceptor::class,
                    $interfaceToCallRegistry->getFor(TraceRecordingInterceptor::class, $method),
                    $precedence,
                    CommandBus::class . '||' . EventBus::class . '||' . AsynchronousRunningEndpoint::class . '||' . OrchestratorGateway::class . '||(' . self::USER_MESSAGE_GATEWAYS_POINTCUT . ')'
                )
            );
        }
        $messagingConfiguration->registerMessageChannel(SimpleMessageChannelBuilder::createPublishSubscribeChannel(OnTraceCompleted::CHANNEL));
        $messagingConfiguration->registerMessageChannel(SimpleMessageChannelBuilder::createPublishSubscribeChannel(OnSpanEnded::CHANNEL));

        TracingGatewayRegistration::register($messagingConfiguration, StoredTracingGateway::class, new Definition(StoredTracingGateway::class, [new Reference(TraceStore::class), new Reference(EcotoneClockInterface::class), $this->pollingProjectionNames]));

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
