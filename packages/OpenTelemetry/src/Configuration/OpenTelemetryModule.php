<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry\Configuration;

use Ecotone\Amqp\Transaction\AmqpTransactionInterceptor;
use Ecotone\AnnotationFinder\AnnotationFinder;
use Ecotone\Messaging\Attribute\ModuleAnnotation;
use Ecotone\Messaging\Config\Annotation\AnnotationModule;
use Ecotone\Messaging\Config\Annotation\ModuleConfiguration\NoExternalConfigurationModule;
use Ecotone\Messaging\Config\Configuration;
use Ecotone\Messaging\Config\ModulePackageList;
use Ecotone\Messaging\Config\ModuleReferenceSearchService;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Precedence;
use Ecotone\OpenTelemetry\TracingChannelAdapterBuilder;
use Ecotone\OpenTelemetry\TracingEndpointAndGatewayInterceptor;

#[ModuleAnnotation]
final class OpenTelemetryModule extends NoExternalConfigurationModule implements AnnotationModule
{
    public static function create(AnnotationFinder $annotationRegistrationService, InterfaceToCallRegistry $interfaceToCallRegistry): static
    {
        return new self();
    }

    public function prepare(Configuration $messagingConfiguration, array $extensionObjects, ModuleReferenceSearchService $moduleReferenceSearchService, InterfaceToCallRegistry $interfaceToCallRegistry): void
    {
        $messagingConfiguration
            ->registerChannelInterceptor(new TracingChannelAdapterBuilder())
            ->registerAroundMethodInterceptor(
                AroundInterceptorReference::createWithDirectObjectAndResolveConverters(
                    $interfaceToCallRegistry,
                    new TracingEndpointAndGatewayInterceptor(),
                    'trace',
                    Precedence::DATABASE_TRANSACTION_PRECEDENCE - 100,
                    "*"
                )
            );;
    }

    public function canHandle($extensionObject): bool
    {
        return false;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::TRACING_PACKAGE;
    }
}