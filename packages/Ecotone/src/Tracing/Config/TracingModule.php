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
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Gateway\EventBus;
use Ecotone\Api\Gateway\QueryBus;
use Ecotone\Api\Interceptor\Precedence;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptorBuilder;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\MessageHandling\MetadataPropagator\MessageHeadersPropagatorInterceptor;
use Ecotone\Tracing\CompositeSpanRecorder;
use Ecotone\Tracing\EcotoneTracer;
use Ecotone\Tracing\Tracer;
use Ecotone\Tracing\TracingInterceptor;

use function implode;

#[ModuleAnnotation]
/**
 * licence Apache-2.0
 */
final class TracingModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $tracingConfiguration = ExtensionObjectResolver::resolveUnique(TracingConfiguration::class, $extensionObjects, TracingConfiguration::createWithDefaults());
        $tracerReferences = ExtensionObjectResolver::resolve(TracerReference::class, $extensionObjects);
        if (count($tracerReferences) > 1) {
            throw ConfigurationException::create('Only one Tracer can be active, yet these were registered: ' . implode(', ', array_map(fn (TracerReference $reference): string => $reference->referenceName, $tracerReferences)) . '. Enable a single tracing package.');
        }

        if ($tracingConfiguration->spanRecorderReferenceNames === [] && $tracerReferences === []) {
            return;
        }

        $messagingConfiguration->registerServiceDefinition(
            SpanRecorder::class,
            new Definition(CompositeSpanRecorder::class, [
                array_map(fn (string $referenceName): Reference => new Reference($referenceName), $tracingConfiguration->spanRecorderReferenceNames),
            ])
        );
        $messagingConfiguration->registerServiceDefinition(
            Tracer::class,
            $tracerReferences === []
                ? new Definition(EcotoneTracer::class, [new Reference(EcotoneClockInterface::class), new Reference(SpanRecorder::class)])
                : new Reference($tracerReferences[0]->referenceName)
        );
        $messagingConfiguration->addCompilerPass(new RegisterSpanEventLoggerCompilerPass());
        $messagingConfiguration->registerServiceDefinition(
            TracingInterceptor::class,
            new Definition(TracingInterceptor::class, [new Reference(Tracer::class), new Reference(MessageBuilder::class)])
        );

        foreach ([
            'traceCommandHandler' => CommandHandler::class,
            'traceQueryHandler' => QueryHandler::class,
            'traceEventHandler' => EventHandler::class,
            'traceMessageHandler' => InternalHandler::class . '&&not(' . MessageHeadersPropagatorInterceptor::class . ')',
            'traceCommandBus' => CommandBus::class,
            'traceQueryBus' => QueryBus::class,
            'traceEventBus' => EventBus::class,
            'traceAsynchronousEndpoint' => AsynchronousRunningEndpoint::class,
            'traceDistributedBus' => DistributedBus::class,
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
}
