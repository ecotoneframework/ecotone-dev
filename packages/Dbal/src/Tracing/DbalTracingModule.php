<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\MessageGateway;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\Attribute\OrchestratorGateway;
use Ecotone\Api\Attribute\Polling;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalConnectionReference;
use Ecotone\Api\Dbal\ExtensionObject\MultiTenantConfiguration;
use Ecotone\Api\Dbal\Tracing\FlowCompleted;
use Ecotone\Api\Dbal\Tracing\FlowStepCompleted;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\Dbal\Tracing\OnFlowCompleted;
use Ecotone\Api\Dbal\Tracing\OnFlowStepCompleted;
use Ecotone\Api\Dbal\Tracing\TracingGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionFlush;
use Ecotone\Dbal\Database\DbalTableManagerReference;
use Ecotone\Dbal\Database\SpansTableManager;
use Ecotone\Dbal\Database\TracesTableManager;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\BootstrapConfiguration;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\ConsoleCommandConfiguration;
use Ecotone\Messaging\Config\ConsoleInvocationResolver;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeaderBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayHeadersBuilder;
use Ecotone\Messaging\Handler\Gateway\ParameterToMessageConverter\GatewayPayloadBuilder;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivatorBuilder;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\LicensingException;
use Ecotone\Tracing\Config\SpanRecorderReference;
use Ecotone\Tracing\Tracer;

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
final class DbalTracingModule extends NoExternalConfigurationModule implements AnnotationModule
{
    private const REMOVE_EXPIRED_TRACES_CHANNEL = 'ecotone.tracing.removeExpiredTraces';
    private const USER_MESSAGE_GATEWAYS_POINTCUT = MessageGateway::class . '&&not(' . CommandBus::class . ')&&not(' . EventBus::class . ')&&not(' . QueryBus::class . ')';

    /**
     * @param string[] $flowCompletedSubscribers
     * @param string[] $flowStepCompletedSubscribers
     * @param array<array{attribute: string, notification: string, class: string, method: string}> $subscriberMethods
     * @param string[] $pollingProjectionNames
     */
    private function __construct(
        private array $flowCompletedSubscribers,
        private array $flowStepCompletedSubscribers,
        private array $subscriberMethods,
        private array $pollingProjectionNames,
    ) {
    }

    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self(
            self::placementsOf($annotationRegistrationService, OnFlowCompleted::class),
            self::placementsOf($annotationRegistrationService, OnFlowStepCompleted::class),
            [
                ...self::subscriberMethodsOf($annotationRegistrationService, OnFlowCompleted::class, FlowCompleted::class),
                ...self::subscriberMethodsOf($annotationRegistrationService, OnFlowStepCompleted::class, FlowStepCompleted::class),
            ],
            self::pollingProjectionNames($annotationRegistrationService),
        );
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $flowTracking = self::flowTrackingOf($extensionObjects);
        $this->refuseMisconfiguredFlowTracking($messagingConfiguration, $flowTracking);
        $this->refuseSubscribersThatCannotReceiveTheirNotification($interfaceToCallRegistry);
        $connectionReference = self::connectionReferenceOf($extensionObjects);
        $consoleInvocationPrefix = ConsoleInvocationResolver::resolveConsolePrefix(ExtensionObjectResolver::resolveUnique(BootstrapConfiguration::class, $extensionObjects, BootstrapConfiguration::createWithDefaults()));
        $storesOnDatabase = $flowTracking !== null && ! $flowTracking->inMemory;
        $messagingConfiguration->registerServiceDefinition(
            TracesTableManager::class,
            new Definition(TracesTableManager::class, [TracesTableManager::DEFAULT_TABLE, $storesOnDatabase, $consoleInvocationPrefix, $connectionReference])
        );
        $messagingConfiguration->registerServiceDefinition(
            SpansTableManager::class,
            new Definition(SpansTableManager::class, [SpansTableManager::DEFAULT_TABLE, $storesOnDatabase, $consoleInvocationPrefix, $connectionReference])
        );
        if ($flowTracking === null) {
            self::registerTracingGateway($messagingConfiguration, UnavailableTracingGateway::class, new Definition(UnavailableTracingGateway::class, ['TracingGateway needs flow tracking, which records every flow and decides when it is complete. Register FlowTrackingConfiguration::createWithDefaults() from a #[ServiceContext] method.']));

            return;
        }

        $messagingConfiguration->registerServiceDefinition(
            TraceStore::class,
            $storesOnDatabase
                ? new Definition(DbalTraceStore::class, [new Reference($connectionReference), new Reference(TracesTableManager::class), new Reference(SpansTableManager::class), new Reference(EcotoneClockInterface::class)])
                : new Definition(InMemoryTraceStore::class)
        );
        $messagingConfiguration->registerServiceDefinition(
            StoringSpanRecorder::class,
            new Definition(StoringSpanRecorder::class, [
                new Reference(TraceStore::class),
                $flowTracking->notifications && $this->flowStepCompletedSubscribers !== [],
                $flowTracking->notifications && $this->flowCompletedSubscribers !== [],
                $flowTracking->onlyRequestedFlows,
                new Reference(EcotoneClockInterface::class),
            ])
        );
        $messagingConfiguration->registerServiceDefinition(
            TraceRecordingInterceptor::class,
            new Definition(TraceRecordingInterceptor::class, [new Reference(StoringSpanRecorder::class), new Reference(Tracer::class), new Reference(MessagingEntrypointService::class), new Reference(LoggingGateway::class)])
        );
        foreach (['publishAfterCommit' => Precedence::TRACE_PUBLICATION_PRECEDENCE, 'recordUnitOfWork' => Precedence::TRACE_RECORDING_PRECEDENCE] as $method => $precedence) {
            $messagingConfiguration->registerAroundMethodInterceptor(
                AroundInterceptorBuilder::create(
                    TraceRecordingInterceptor::class,
                    $interfaceToCallRegistry->getFor(TraceRecordingInterceptor::class, $method),
                    $precedence,
                    CommandBus::class . '||' . EventBus::class . '||' . QueryBus::class . '||' . DistributedBus::class . '||' . AsynchronousRunningEndpoint::class . '||' . OrchestratorGateway::class . '||(' . self::USER_MESSAGE_GATEWAYS_POINTCUT . ')'
                )
            );
        }
        $messagingConfiguration->registerAroundMethodInterceptor(
            AroundInterceptorBuilder::create(
                TraceRecordingInterceptor::class,
                $interfaceToCallRegistry->getFor(TraceRecordingInterceptor::class, 'recordProjectionBatch'),
                Precedence::TRACE_RECORDING_PRECEDENCE,
                ProjectionFlush::class
            )
        );
        if ($flowTracking->notifications) {
            $messagingConfiguration->registerMessageChannel(SimpleMessageChannelBuilder::createPublishSubscribeChannel(OnFlowCompleted::CHANNEL));
            $messagingConfiguration->registerMessageChannel(SimpleMessageChannelBuilder::createPublishSubscribeChannel(OnFlowStepCompleted::CHANNEL));
        }
        if ($flowTracking->tracingGateway) {
            $multiTenancy = $flowTracking->inMemory ? null : self::multiTenancyWithoutDefaultConnectionOf($extensionObjects);
            self::registerTracingGateway($messagingConfiguration, StoredTracingGateway::class, new Definition(StoredTracingGateway::class, [
                new Reference(TraceStore::class),
                new Reference(EcotoneClockInterface::class),
                $this->pollingProjectionNames,
                $multiTenancy?->tenantHeaderName,
                $multiTenancy === null ? null : (string) array_key_first($multiTenancy->tenantToConnectionMapping),
            ]));
        } else {
            self::registerTracingGateway($messagingConfiguration, UnavailableTracingGateway::class, new Definition(UnavailableTracingGateway::class, ['TracingGateway is turned off by FlowTrackingConfiguration::withTracingGateway(false). Remove that call to ask whether a flow completed.']));
        }
        $messagingConfiguration->registerServiceDefinition(
            ExpiredTraceRemover::class,
            new Definition(ExpiredTraceRemover::class, [new Reference(TraceStore::class), new Reference(EcotoneClockInterface::class), $flowTracking->retention->inMilliseconds()])
        );
        $messagingConfiguration
            ->registerMessageHandler(MessageProcessorActivatorBuilder::createForMethod(ExpiredTraceRemover::class, 'removeExpiredTraces')->withInputChannelName(self::REMOVE_EXPIRED_TRACES_CHANNEL))
            ->registerConsoleCommand(ConsoleCommandConfiguration::create(self::REMOVE_EXPIRED_TRACES_CHANNEL, 'ecotone:tracing:remove-expired-traces', [], 'Removes tracked flows older than the configured retention'));
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions): array
    {
        $connectionReference = self::connectionReferenceOf($serviceExtensions);
        $extensions = [
            new DbalTableManagerReference(TracesTableManager::class, $connectionReference),
            new DbalTableManagerReference(SpansTableManager::class, $connectionReference),
        ];
        if (self::flowTrackingOf($serviceExtensions) !== null) {
            $extensions[] = new SpanRecorderReference(StoringSpanRecorder::class);
        }

        return $extensions;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }

    /**
     * @param object[] $extensionObjects
     */
    private static function flowTrackingOf(array $extensionObjects): ?FlowTrackingConfiguration
    {
        $configurations = ExtensionObjectResolver::resolve(FlowTrackingConfiguration::class, $extensionObjects);
        if (count($configurations) > 1) {
            throw ConfigurationException::create(sprintf('FlowTrackingConfiguration was registered %d times, so it is not clear which settings apply. Register it once, from one #[ServiceContext] method, chaining every setting on it: FlowTrackingConfiguration::createWithDefaults()->withRetention(...)->withNotifications(...).', count($configurations)));
        }

        return $configurations[0] ?? null;
    }

    private function refuseMisconfiguredFlowTracking(Configuration $messagingConfiguration, ?FlowTrackingConfiguration $flowTracking): void
    {
        $subscribers = [...$this->flowCompletedSubscribers, ...$this->flowStepCompletedSubscribers];
        $enterpriseUsages = [...($flowTracking !== null ? ['FlowTrackingConfiguration'] : []), ...$subscribers];
        if ($enterpriseUsages !== [] && ! $messagingConfiguration->isRunningForEnterpriseLicence()) {
            throw LicensingException::create(sprintf(
                '%s %s an Ecotone Enterprise licence. Remove %s to run without one.',
                implode(' and ', $enterpriseUsages),
                count($enterpriseUsages) === 1 ? 'requires' : 'require',
                count($enterpriseUsages) === 1 ? 'it' : 'them',
            ));
        }
        if ($subscribers === []) {
            return;
        }
        if ($flowTracking === null) {
            throw ConfigurationException::create(implode(', ', $subscribers) . ' needs flow tracking, which decides when a flow is complete. Register FlowTrackingConfiguration::createWithDefaults() from a #[ServiceContext] method.');
        }
        if (! $flowTracking->notifications) {
            throw ConfigurationException::create(implode(', ', $subscribers) . ' would never be called, because FlowTrackingConfiguration::withNotifications(false) turned notifications off. Remove that call, or remove the attribute.');
        }
    }

    private function refuseSubscribersThatCannotReceiveTheirNotification(InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        foreach ($this->subscriberMethods as $subscriber) {
            $interfaceToCall = $interfaceToCallRegistry->getFor($subscriber['class'], $subscriber['method']);
            if ($interfaceToCall->hasNoParameters()) {
                continue;
            }
            $declaredType = $interfaceToCall->getFirstParameter()->getTypeDescriptor();
            if ($declaredType->acceptType(Type::create($subscriber['notification']))) {
                continue;
            }

            throw ConfigurationException::create(sprintf(
                '%s on %s::%s is passed a %s as its first parameter, but declares it as %s, so every notification would fail. Declare that parameter as %s.',
                $subscriber['attribute'],
                $subscriber['class'],
                $subscriber['method'],
                $subscriber['notification'],
                $declaredType->toString(),
                $subscriber['notification'],
            ));
        }
    }

    /**
     * @return array<array{attribute: string, notification: string, class: string, method: string}>
     */
    private static function subscriberMethodsOf(AnnotationFinder $annotationFinder, string $attributeClassName, string $notificationClassName): array
    {
        $subscribers = [];
        foreach ($annotationFinder->findAnnotatedMethods($attributeClassName) as $annotatedMethod) {
            $subscribers[] = [
                'attribute' => '#[' . substr((string) strrchr('\\' . $attributeClassName, '\\'), 1) . ']',
                'notification' => $notificationClassName,
                'class' => $annotatedMethod->getClassName(),
                'method' => $annotatedMethod->getMethodName(),
            ];
        }

        return $subscribers;
    }

    private static function registerTracingGateway(Configuration $messagingConfiguration, string $serviceClass, Definition $service): void
    {
        $messagingConfiguration->registerServiceDefinition($serviceClass, $service);
        foreach ([
            'isCompleted' => [GatewayPayloadBuilder::create('tracingId'), GatewayHeadersBuilder::create('metadata')],
            'isCompletedFor' => [GatewayPayloadBuilder::create('tracingId'), GatewayHeaderBuilder::create('destination', StoredTracingGateway::DESTINATION_HEADER), GatewayHeadersBuilder::create('metadata')],
            'await' => [GatewayPayloadBuilder::create('tracingId'), GatewayHeaderBuilder::create('timeout', StoredTracingGateway::TIMEOUT_HEADER), GatewayHeadersBuilder::create('metadata')],
            'awaitFor' => [GatewayPayloadBuilder::create('tracingId'), GatewayHeaderBuilder::create('destination', StoredTracingGateway::DESTINATION_HEADER), GatewayHeaderBuilder::create('timeout', StoredTracingGateway::TIMEOUT_HEADER), GatewayHeadersBuilder::create('metadata')],
        ] as $method => $parameterConverters) {
            $channel = 'ecotone.tracing.gateway.' . $method;
            $messagingConfiguration
                ->registerMessageHandler(MessageProcessorActivatorBuilder::createForMethod($serviceClass, $method)->withInputChannelName($channel))
                ->registerGatewayBuilder(GatewayProxyBuilder::create(TracingGateway::class, TracingGateway::class, $method, $channel)->withParameterConverters($parameterConverters));
        }
    }

    /**
     * @param object[] $extensionObjects
     */
    private static function connectionReferenceOf(array $extensionObjects): string
    {
        $dbalConfiguration = ExtensionObjectResolver::resolveUnique(DbalConfiguration::class, $extensionObjects, DbalConfiguration::createWithDefaults());

        return $dbalConfiguration->defaultConnectionReferenceNames[0] ?? DbalConnectionReference::DEFAULT;
    }

    /**
     * @param object[] $extensionObjects
     */
    private static function multiTenancyWithoutDefaultConnectionOf(array $extensionObjects): ?MultiTenantConfiguration
    {
        foreach (ExtensionObjectResolver::resolve(MultiTenantConfiguration::class, $extensionObjects) as $multiTenantConfiguration) {
            if ($multiTenantConfiguration->getReferenceName() === self::connectionReferenceOf($extensionObjects) && $multiTenantConfiguration->defaultConnectionName === null) {
                return $multiTenantConfiguration;
            }
        }

        return null;
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
