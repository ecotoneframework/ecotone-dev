<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry\Configuration;

use Ecotone\Amqp\Transaction\AmqpTransactionInterceptor;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\ExtensionObjectResolver;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Precedence;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\EventBus;
use Ecotone\Modelling\QueryBus;
use Ecotone\OpenTelemetry\TracingChannelAdapterBuilder;
use Ecotone\OpenTelemetry\TracerInterceptor;

#[ModuleAnnotation]
final class OpenTelemetryModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $tracingConfiguration = ExtensionObjectResolver::resolveUnique(TracingConfiguration::class, $extensionObjects, TracingConfiguration::createWithDefaults());

        if ($tracingConfiguration->higherThanOrEqualTo(TracingConfiguration::TRACING_LEVEL_FRAMEWORK)) {
            $messagingConfiguration->registerChannelInterceptor(new TracingChannelAdapterBuilder());
            $this->registerTracerFor('trace', "*", $messagingConfiguration, $interfaceToCallRegistry);
        }

        $this->registerTracerFor('traceCommandHandler', CommandHandler::class, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerTracerFor('traceQueryHandler', QueryHandler::class, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerTracerFor('traceEventHandler', EventHandler::class, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerTracerFor('traceCommandBus', CommandBus::class, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerTracerFor('traceQueryBus', QueryBus::class, $messagingConfiguration, $interfaceToCallRegistry);
        $this->registerTracerFor('traceEventBus', EventBus::class, $messagingConfiguration, $interfaceToCallRegistry);
    }

    public function canHandle($extensionObject): bool
    {
        return $extensionObject instanceof TracingConfiguration;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::TRACING_PACKAGE;
    }

    private function registerTracerFor(string $tracingMethodToInvoke, string $pointcut, Configuration $messagingConfiguration, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $messagingConfiguration
            ->registerAroundMethodInterceptor(
                AroundInterceptorReference::createWithDirectObjectAndResolveConverters(
                    $interfaceToCallRegistry,
                    new TracerInterceptor(),
                    $tracingMethodToInvoke,
                    Precedence::DATABASE_TRANSACTION_PRECEDENCE - 100,
                    $pointcut
                )
            );
    }
}