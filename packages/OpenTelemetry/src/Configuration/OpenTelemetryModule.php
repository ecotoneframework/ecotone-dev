<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry\Configuration;

use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Attribute\InternalHandler;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Precedence;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\DistributedBus;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\QueryBus;
use Ecotone\OpenTelemetry\EcotoneForcedTraceFlush;
use Ecotone\OpenTelemetry\TracerInterceptor;
use Ecotone\OpenTelemetry\TracingChannelAdapterBuilder;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use Psr\Log\LoggerInterface;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class OpenTelemetryModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $tracingConfiguration = ExtensionObjectResolver::resolveUnique(TracingConfiguration::class, $extensionObjects, TracingConfiguration::createWithDefaults());
        $messageChannelBuilders = ExtensionObjectResolver::resolve(MessageChannelBuilder::class, $extensionObjects);

        $messagingConfiguration->registerServiceDefinition(
            TracerInterceptor::class,
            new Definition(TracerInterceptor::class, [
                new Reference(TracerProviderInterface::class),
                new Reference(LoggerInterface::class),
            ])
        );
        if ($tracingConfiguration->higherThanOrEqualTo(TracingConfiguration::TRACING_LEVEL_FRAMEWORK)) {
            $this->registerTracerFor('trace', '*', $messagingConfiguration, $interfaceToCallRegistry);
        }

        foreach ($messageChannelBuilders as $messageChannelBuilder) {
            if ($messageChannelBuilder->isPollable()) {
                $messagingConfiguration->registerChannelInterceptor(new TracingChannelAdapterBuilder($messageChannelBuilder->getMessageChannelName()));
            }
        }

        $this->registerTracerFor('traceCommandHandler', CommandHandler::class, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerTracerFor('traceQueryHandler', QueryHandler::class, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerTracerFor('traceEventHandler', EventHandler::class, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerTracerFor('traceMessageHandler', InternalHandler::class, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerTracerFor('traceCommandBus', CommandBus::class, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerTracerFor('traceQueryBus', QueryBus::class, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerTracerFor('traceEventBus', EventBus::class, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerTracerFor('traceAsynchronousEndpoint', AsynchronousRunningEndpoint::class, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerTracerFor('traceLogs', LoggingGateway::class, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerTracerFor('traceDistributedBus', DistributedBus::class, $messagingConfiguration, $interfaceToCallRegistry);

        $messagingConfiguration->registerBeforeMethodInterceptor(
            MethodInterceptorBuilder::create(
                new Definition(TracerInterceptor::class, [
                    Reference::to(TracerProviderInterface::class),
                ]),
                $interfaceToCallRegistry->getFor(TracerInterceptor::class, 'provideContextForDistributedBus'),
                0,
                DistributedBus::class
            )
        );

        $pointcut = '';
        if ($tracingConfiguration->isFlushForcedOnBusExecution()) {
            $pointcut = CommandBus::class . '||' . QueryBus::class . '||' . DistributedBus::class;
        }
        if ($tracingConfiguration->isForceFlushOnAsynchronousMessageHandled()) {
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

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof TracingConfiguration || $extensionObject instanceof MessageChannelBuilder;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::TRACING_PACKAGE;
    }

    private function registerTracerFor(string $tracingMethodToInvoke, string $pointcut, Configuration $messagingConfiguration, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $messagingConfiguration
            ->registerAroundMethodInterceptor(
                AroundInterceptorBuilder::create(
                    TracerInterceptor::class,
                    $interfaceToCallRegistry->getFor(TracerInterceptor::class, $tracingMethodToInvoke),
                    Precedence::TRACING_PRECEDENCE,
                    $pointcut
                )
            );
    }
}
