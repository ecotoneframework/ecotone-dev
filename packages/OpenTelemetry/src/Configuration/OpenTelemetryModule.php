<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry\Configuration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\ModuleAnnotation;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\OpenTelemetry\OpenTelemetryConfiguration;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Lite\Test\Configuration\ProvidedReferences;
use Ecotone\Lite\Test\Configuration\ResolvesExtensionObjectsForFlowTesting;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\OpenTelemetry\EcotoneForcedTraceFlush;
use Ecotone\OpenTelemetry\OpenTelemetryTracer;
use Ecotone\Tracing\Config\TracerReference;
use OpenTelemetry\API\Trace\TracerProviderInterface;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class OpenTelemetryModule extends NoExternalConfigurationModule implements AnnotationModule, ResolvesExtensionObjectsForFlowTesting
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function getModuleExtensions(ServiceConfiguration $serviceConfiguration, array $serviceExtensions, ?InterfaceToCallRegistry $interfaceToCallRegistry = null): array
    {
        if (ExtensionObjectResolver::contains(NoTracerProviderGiven::class, $serviceExtensions)) {
            return [];
        }

        return [new TracerReference(OpenTelemetryTracer::class)];
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $openTelemetryConfiguration = ExtensionObjectResolver::resolveUnique(OpenTelemetryConfiguration::class, $extensionObjects, OpenTelemetryConfiguration::createWithDefaults());

        if (ExtensionObjectResolver::contains(NoTracerProviderGiven::class, $extensionObjects)) {
            return;
        }

        $messagingConfiguration->registerServiceDefinition(
            OpenTelemetryTracer::class,
            new Definition(OpenTelemetryTracer::class, [
                new Reference(TracerProviderInterface::class),
                new Reference(EcotoneClockInterface::class),
                new Reference(SpanRecorder::class),
            ])
        );

        $pointcut = '';
        if ($openTelemetryConfiguration->forceFlushOnBusExecution) {
            $pointcut = CommandBus::class . '||' . QueryBus::class . '||' . DistributedBus::class;
        }
        if ($openTelemetryConfiguration->forceFlushOnAsynchronousMessageHandled) {
            $pointcut .= $pointcut !== '' ? '||' . AsynchronousRunningEndpoint::class : AsynchronousRunningEndpoint::class;
        }

        if ($pointcut !== '') {
            $messagingConfiguration->registerServiceDefinition(
                EcotoneForcedTraceFlush::class,
                new Definition(EcotoneForcedTraceFlush::class, [
                    new Reference(TracerProviderInterface::class),
                ])
            );

            $messagingConfiguration->registerAroundMethodInterceptor(
                AroundInterceptorBuilder::create(
                    EcotoneForcedTraceFlush::class,
                    $interfaceToCallRegistry->getFor(EcotoneForcedTraceFlush::class, 'flush'),
                    Precedence::TRACING_PRECEDENCE - 1,
                    $pointcut
                )
            );
        }
    }

    public function resolveForFlowTesting(array $extensionObjects, ProvidedReferences $providedReferences): array
    {
        if ($providedReferences->has(TracerProviderInterface::class)) {
            return $extensionObjects;
        }

        return [...$extensionObjects, new NoTracerProviderGiven()];
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::TRACING_PACKAGE;
    }
}
